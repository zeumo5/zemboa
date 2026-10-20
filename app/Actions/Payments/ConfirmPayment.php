<?php

namespace App\Actions\Payments;


use App\Models\PosRegister;
use App\Models\PosSession;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\User;
use App\Actions\Stock\ConvertStockReservation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmPayment
{

    public function __construct(
        private ConvertStockReservation $convertStockReservation,
        private FinalizeOrderPayment $finalizeOrderPayment
    ) {}


    public function execute(
        int $paymentId,
        ?int $userId = null,
        ?string $balanceDueAt = null
    ): Payment {
        return DB::transaction(function () use (
            $paymentId,
            $userId,
            $balanceDueAt
        ) {
            // Identifier la commande dans le contexte de la boutique.
            $paymentLookup = Payment::query()
                ->whereKey($paymentId)
                ->firstOrFail();


            $posSession = null;

            if ($paymentLookup->pos_session_id !== null) {
                // Identifier la session POS du paiement.
                $snapshot = PosSession::query()
                    ->where('store_id', $paymentLookup->store_id)
                    ->whereKey($paymentLookup->pos_session_id)
                    ->firstOrFail();

                // Verrouiller la caisse avant la commande,
                // comme dans les autres actions POS.
                $register = PosRegister::query()
                    ->where('store_id', $paymentLookup->store_id)
                    ->whereKey($snapshot->pos_register_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $posSession = PosSession::query()
                    ->where('store_id', $paymentLookup->store_id)
                    ->where('pos_register_id', $register->id)
                    ->whereKey($snapshot->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $register->status !== 'ACTIVE' ||
                    $posSession->status !== 'OPEN' ||
                    (int) $posSession->open_slot !== 1
                ) {
                    throw ValidationException::withMessages([
                        'session' =>
                        __('payments.pos_session_must_be_open'),
                    ]);
                }
            }


            // Toujours verrouiller la commande en premier.
            $order = Order::query()
                ->whereKey($paymentLookup->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();


            // Le rattachement POS ne doit pas avoir changé
            // pendant l'acquisition des verrous.
            if (
                (string) ($payment->pos_session_id ?? '') !==
                (string) ($paymentLookup->pos_session_id ?? '')
            ) {
                throw ValidationException::withMessages([
                    'session' =>
                    __('payments.payment_session_changed'),
                ]);
            }

            // Une commande POS ne peut pas être payée en espèces
            // par un paiement dépourvu de session POS.
            if (
                $order->pos_session_id !== null &&
                $payment->pos_session_id === null
            ) {
                throw ValidationException::withMessages([
                    'session' =>
                   __('payments.cash_requires_pos_session'),
                ]);
            }


            if (
                $payment->order_id !== $order->id ||
                $payment->store_id !== $order->store_id
            ) {
                throw ValidationException::withMessages([
                    'payment' => __('payments.payment_order_mismatch')
                ]);
            }

            if ($payment->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'status' =>__('payments.payment_already_processed'),
                ]);
            }

            if ($order->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'order' => __('payments.order_cannot_receive_payment'),
                ]);
            }

            if (!in_array($order->payment_status, [
                'UNPAID',
                'PARTIALLY_PAID',
            ], true)) {
                throw ValidationException::withMessages([
                    'payment_status' => __('payments.order_cannot_be_paid'),
                ]);
            }

            // Pour l'instant, seule une opération CASH
            // explicitement validée par un caissier est acceptée.
            // Les confirmations Mobile Money seront gérées
            // par une intégration sécurisée avec le prestataire.
            if ($payment->method !== 'CASH') {
                throw ValidationException::withMessages([
                   'method' => __('payments.mobile_money_confirmation_unavailable'),
                ]);
            }

            if ($userId === null) {
                throw ValidationException::withMessages([
                    'user' => __('payments.cashier_required'),
                ]);
            }

            // Vérifier que le caissier appartient
            // à la boutique de la commande.
            $cashier = User::query()
                ->whereKey($userId)
                ->where('store_id', $order->store_id)
                ->first();

            if ($cashier === null) {
                throw ValidationException::withMessages([

                   'user' => __('payments.cashier_wrong_store'),
                ]);
            }


            // Vérifier que le caissier est bien
            // l'utilisateur actuellement connecté.
            if (auth()->id() !== $cashier->id) {
                throw new AuthorizationException(
                   __('payments.confirm_for_other_user_denied'),
                );
            }

            //
            // Autorisation de confirmation en caisse.
            // Un livreur ne peut pas confirmer un paiement CASH,
            // même s'il possède accidentellement une permission
            // de confirmation attribuée par un autre rôle.
            if (
                !$cashier->hasPermission('payments.confirm') ||
                $cashier->hasRole('DELIVERY_AGENT')
            ) {
                throw new AuthorizationException(
                   __('payments.confirmation_denied'),
                );
            }

            // Contrôles supplémentaires pour les paiements POS.
            // Les paiements classiques conservent leur fonctionnement.
            if ($posSession !== null) {
                $supervisor = $cashier->roles()
                    ->whereIn('code', [
                        'STORE_OWNER',
                        'STORE_MANAGER',
                    ])
                    ->exists();

                $cashierRole = $cashier->hasRole('CASHIER');

                $forbiddenRole = $cashier->roles()
                    ->whereIn('code', [
                        'DELIVERY_AGENT',
                        'SUPER_ADMIN',
                    ])
                    ->exists();

                if (
                    $cashier->status !== 'ACTIVE' ||
                    $cashier->store()->value('status') !== 'ACTIVE' ||
                    ! $cashier->hasPermission('pos.sales.create') ||
                    (! $supervisor && ! $cashierRole) ||
                    $forbiddenRole ||
                    (
                        ! $supervisor &&
                        (int) $posSession->opened_by_user_id
                        !== (int) $cashier->id
                    )
                ) {
                    throw new AuthorizationException(
                        __('payments.pos_confirmation_denied'),
                    );
                }
            }

            $amountToCents = fn(string $amount): int =>
            $this->finalizeOrderPayment->amountToCents($amount);

            $paymentAmount = $amountToCents($payment->amount);
            $orderTotal = $amountToCents($order->total);

            if ($paymentAmount <= 0 || $orderTotal <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.amount_must_be_positive'),
                ]);
            }

            $confirmedPayments = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'CONFIRMED')
                ->lockForUpdate()
                ->get(['amount']);

            $alreadyPaid = $confirmedPayments->sum(
                fn(Payment $item) => $amountToCents($item->amount)
            );

            $alreadyPaidDecimal = sprintf(
                '%d.%02d',
                intdiv($alreadyPaid, 100),
                $alreadyPaid % 100
            );

            $newTotalPaid = $this->finalizeOrderPayment->validatePaymentTotal(
                orderTotal: (string) $order->total,
                alreadyPaid: $alreadyPaidDecimal,
                newPayment: (string) $payment->amount
            );

            // Si la commande reste partiellement payée,
            // elle doit posséder une date limite future
            // pour le paiement du solde.
            if ($newTotalPaid < $orderTotal) {

                if ($order->balance_due_at === null) {
                    Validator::make(
                        [
                            'balance_due_at' => $balanceDueAt,
                        ],
                        [
                            'balance_due_at' => [
                                'required',
                                'date_format:Y-m-d H:i:s',
                                'after:now',
                            ],
                        ],
                        [
                            'balance_due_at.required' =>
                            'La date limite du solde est obligatoire.',
                            'balance_due_at.date_format' =>
                            'Le format attendu est AAAA-MM-JJ HH:MM:SS.',
                            'balance_due_at.after' =>
                            'La date limite doit être dans le futur.',
                        ]
                    )->validate();

                    $order->balance_due_at = $balanceDueAt;
                } elseif ($balanceDueAt !== null) {
                    throw ValidationException::withMessages([
                        'balance_due_at' =>
                        __('payments.balance_due_date_locked'),
                    ]);
                }
            }

            $cashReceived = $payment->cash_received === null
                ? null
                : $amountToCents($payment->cash_received);

            $changeGiven = $payment->change_given === null
                ? 0
                : $amountToCents($payment->change_given);

            if (
                $cashReceived === null ||
                $cashReceived < $paymentAmount ||
                $changeGiven !== $cashReceived - $paymentAmount
            ) {
                throw ValidationException::withMessages([
                    'cash_received' => __('payments.cash_change_invalid'),
                ]);
            }

            return $this->finalizeOrderPayment->completeLockedCashPayment(
                order: $order,
                payment: $payment,
                newTotalPaid: $newTotalPaid,
                converter: $this->convertStockReservation,
                userId: $userId
            );
        });
    }
}
