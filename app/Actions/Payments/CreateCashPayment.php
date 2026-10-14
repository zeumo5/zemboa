<?php
namespace App\Actions\Payments;


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
        string $cashReceived
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
            $storeId
        ) {
            // Verrouiller la commande avant les paiements.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

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
            return Payment::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'method' => 'CASH',
                'status' => 'PENDING',
                'cash_received' => $cashReceived,
                'change_given' => $changeGiven,
            ]);
        }, 3);
    }
}
