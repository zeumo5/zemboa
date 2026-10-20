<?php

namespace App\Actions\Payments;


use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateCashPayment
{
    public function __construct(
        private readonly ValidateStorePaymentRules $paymentRules,
        private readonly FinalizeOrderPayment $finalizer
    ) {}

    public function execute(
        int $orderId,
        string $amount,
        string $cashReceived,
        ?int $posSessionId = null
    ): Payment {
        $storeId = app(TenantContext::class)->storeId();
        $cashier = auth()->user();

        // Autorisation du caissier.
        if (
            ! $cashier instanceof User ||
            (int) $cashier->store_id !== (int) $storeId ||
            ! $cashier->hasPermission('payments.confirm') ||
            $cashier->roles()
            ->where('code', 'DELIVERY_AGENT')
            ->exists()
        ) {
            throw new AuthorizationException(
                'Vous ne pouvez pas enregistrer ce paiement.'
            );
        }

        return DB::transaction(function () use (
            $orderId,
            $amount,
            $cashReceived,
            $storeId,
            $posSessionId,
            $cashier
        ) {

            $posSession = null;

            if ($posSessionId !== null) {
                $supervisor = $cashier->roles()
                    ->whereIn('code', [
                        'STORE_OWNER',
                        'STORE_MANAGER',
                    ])->exists();

                $cashierRole = $cashier->hasRole('CASHIER');

                $forbiddenRole = $cashier->roles()
                    ->whereIn('code', [
                        'DELIVERY_AGENT',
                        'SUPER_ADMIN',
                    ])->exists();

                if (
                    $cashier->status !== 'ACTIVE' ||
                    $cashier->store()->value('status') !== 'ACTIVE' ||
                    (! $supervisor && ! $cashierRole) ||
                    $forbiddenRole ||
                    ! $cashier->hasPermission('pos.sales.create')
                ) {
                    throw new AuthorizationException(
                        'Encaissement POS non autorisé.'
                    );
                }

                // Verrouiller la caisse avant la commande.
                $snapshot = PosSession::query()
                    ->where('store_id', $storeId)
                    ->whereKey($posSessionId)
                    ->firstOrFail();

                $register = PosRegister::query()
                    ->where('store_id', $storeId)
                    ->whereKey($snapshot->pos_register_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $posSession = PosSession::query()
                    ->where('store_id', $storeId)
                    ->where('pos_register_id', $register->id)
                    ->whereKey($posSessionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $register->status !== 'ACTIVE' ||
                    $posSession->status !== 'OPEN' ||
                    (int) $posSession->open_slot !== 1
                ) {
                    throw ValidationException::withMessages([
                        'session' => 'La session de caisse doit être ouverte.',
                    ]);
                }

                if (
                    ! $supervisor &&
                    (int) $posSession->opened_by_user_id
                    !== (int) $cashier->id
                ) {
                    throw new AuthorizationException(
                        'Cette session appartient à un autre caissier.'
                    );
                }
            }

            // Verrouiller la commande avant les paiements.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $order->pos_session_id !== null &&
                $posSession === null
            ) {
                throw ValidationException::withMessages([
                    'session' =>
                    'Une commande POS doit être encaissée dans une session de caisse ouverte.',
                ]);
            }

            if (
                $order->status !== 'PENDING' ||
                ! in_array($order->payment_status, [
                    'UNPAID',
                    'PARTIALLY_PAID',
                ], true)
            ) {
                throw ValidationException::withMessages([
                    'order' => 'Commande non payable.',
                ]);
            }

            // Éviter plusieurs paiements en attente.
            $pendingExists = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'PENDING')
                ->exists();

            if ($pendingExists) {
                throw ValidationException::withMessages([
                    'payment' =>
                    'Un paiement est déjà en attente.',
                ]);
            }

            // Récupérer les paiements confirmés.
            $confirmedPayments = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'CONFIRMED')
                ->lockForUpdate()
                ->get(['amount']);

            $alreadyPaidCents = 0;

            foreach ($confirmedPayments as $payment) {
                $alreadyPaidCents += $this->finalizer
                    ->amountToCents((string) $payment->amount);
            }

            $alreadyPaid = sprintf(
                '%d.%02d',
                intdiv($alreadyPaidCents, 100),
                $alreadyPaidCents % 100
            );

            // Appliquer les règles propres à la boutique.
            $this->paymentRules->execute(
                order: $order,
                alreadyPaid: $alreadyPaid,
                newPayment: $amount
            );

            // Vérifier les espèces et la monnaie.
            $paymentCents = $this->finalizer
                ->amountToCents($amount);

            $receivedCents = $this->finalizer
                ->amountToCents($cashReceived);

            if ($receivedCents < $paymentCents) {
                throw ValidationException::withMessages([
                    'cash_received' =>
                    'Montant reçu insuffisant.',
                ]);
            }

            $changeCents = $receivedCents - $paymentCents;

            $changeGiven = sprintf(
                '%d.%02d',
                intdiv($changeCents, 100),
                $changeCents % 100
            );

            // Enregistrer uniquement un paiement en attente.
            $payment = Payment::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'method' => 'CASH',
                'status' => 'PENDING',
                'cash_received' => $cashReceived,
                'change_given' => $changeGiven,
            ]);

            if ($posSession !== null) {
                $payment->pos_session_id = $posSession->id;
                $payment->save();
            }

            return $payment->refresh();
        }, 3);
    }
}
