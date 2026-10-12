<?php
namespace App\Actions\Payments;


use App\Models\Order;
use App\Models\Payment;
use App\Models\StorePaymentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignMerchantAccountToPayment
{
    public function execute(int $paymentId): Payment
    {
        return DB::transaction(function () use ($paymentId) {

            // Identifier la commande.
            $lookup = Payment::query()
                ->whereKey($paymentId)
                ->firstOrFail();

            // Verrouiller la commande avant le paiement.
            $order = Order::query()
                ->whereKey($lookup->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();

            // Vérifier l'appartenance et le statut.
            if (
                $payment->store_id !== $order->store_id ||
                $payment->order_id !== $order->id ||
                $payment->status !== 'PENDING' ||
                !in_array($payment->method, [
                    'MTN_MOMO',
                    'ORANGE_MONEY',
                ], true)
            ) {
                throw ValidationException::withMessages([
                    'payment' => 'Paiement non autorisé.',
                ]);
            }

            // Rechercher un compte actif de la boutique.
            // BelongsToStore applique le filtre tenant.
            $account = StorePaymentAccount::query()
                ->where('store_id', $order->store_id)
                ->where('method', $payment->method)
                ->where('status', 'ACTIVE')
                ->whereNotNull('verified_at')
                ->whereNotNull('gateway')
                ->whereNotNull('merchant_account_id')
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw ValidationException::withMessages([
                    'merchant_account' =>
                        'Aucun compte marchand vérifié et actif pour ce moyen de paiement.',
                ]);
            }

            // Ne jamais remplacer silencieusement
            // un bénéficiaire déjà associé.
            if (
                $payment->store_payment_account_id !== null &&
                $payment->store_payment_account_id !== $account->id
            ) {
                throw ValidationException::withMessages([
                    'merchant_account' =>
                        'Le bénéficiaire du paiement ne peut pas être modifié.',
                ]);
            }

            $payment->store_payment_account_id = $account->id;
            $payment->save();

            return $payment->refresh();
        });
    }
}
