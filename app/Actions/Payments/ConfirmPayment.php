<?php

namespace App\Actions\Payments;



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

            // Toujours verrouiller la commande en premier.
            $order = Order::query()
                ->whereKey($paymentLookup->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $payment->order_id !== $order->id ||
                $payment->store_id !== $order->store_id
            ) {
                throw ValidationException::withMessages([
                    'payment' => 'Le paiement ne correspond pas à cette commande.',
                ]);
            }

            if ($payment->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'status' => 'Ce paiement a déjà été traité.',
                ]);
            }

            if ($order->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'order' => 'Cette commande ne peut plus recevoir de paiement.',
                ]);
            }

            if (!in_array($order->payment_status, [
                'UNPAID',
                'PARTIALLY_PAID',
            ], true)) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Cette commande ne peut plus être payée.',
                ]);
            }

            // Pour l'instant, seule une opération CASH
            // explicitement validée par un caissier est acceptée.
            // Les confirmations Mobile Money seront gérées
            // par une intégration sécurisée avec le prestataire.
            if ($payment->method !== 'CASH') {
                throw ValidationException::withMessages([
                    'method' => 'La confirmation Mobile Money n’est pas encore disponible.',
                ]);
            }

            if ($userId === null) {
                throw ValidationException::withMessages([
                    'user' => 'Un caissier identifié est obligatoire.',
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
                    'user' =>
                    'Ce caissier n’appartient pas à cette boutique.',
                ]);
            }


            // Vérifier que le caissier est bien
            // l'utilisateur actuellement connecté.
            if (auth()->id() !== $cashier->id) {
                throw new AuthorizationException(
                    'Vous ne pouvez pas confirmer un paiement au nom d’un autre utilisateur.'
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
                    'Vous ne pouvez pas confirmer ce paiement.'
                );
            }





            $amountToCents = fn(string $amount): int =>
            $this->finalizeOrderPayment->amountToCents($amount);

            $paymentAmount = $amountToCents($payment->amount);
            $orderTotal = $amountToCents($order->total);

            if ($paymentAmount <= 0 || $orderTotal <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Le montant doit être supérieur à zéro.',
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
                        'La date limite existe déjà. Sa modification nécessite une opération distincte.',
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
                    'cash_received' => 'Le montant reçu ou la monnaie rendue est incorrect.',
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
