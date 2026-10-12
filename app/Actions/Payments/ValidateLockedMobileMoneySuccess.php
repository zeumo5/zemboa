<?php
namespace App\Actions\Payments;


use App\Models\Order;
use App\Models\Payment;
use App\Models\StorePaymentAccount;
use App\Payments\MobileMoneyVerificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ValidateLockedMobileMoneySuccess
{
    public function execute(
        int $paymentId,
        MobileMoneyVerificationResult $result
    ): Payment {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'Une transaction SQL est obligatoire.'
            );
        }

        // Retrouver la commande, puis la verrouiller
        // avant le paiement : même ordre de verrouillage
        // que les autres actions de paiement.
        $lookup = Payment::query()
            ->whereKey($paymentId)
            ->firstOrFail();

        $order = Order::query()
            ->whereKey($lookup->order_id)
            ->lockForUpdate()
            ->firstOrFail();

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        // Vérifier à nouveau les liens et les statuts.
        if (
            $payment->order_id !== $order->id ||
            $payment->store_id !== $order->store_id ||
            $payment->status !== 'PENDING' ||
            $order->status !== 'PENDING' ||
            !in_array(
                $order->payment_status,
                ['UNPAID', 'PARTIALLY_PAID'],
                true
            ) ||
            !in_array(
                $payment->method,
                ['MTN_MOMO', 'ORANGE_MONEY'],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Paiement non confirmable.',
            ]);
        }

        // Ne traiter qu'un succès déjà vérifié
        // par le service serveur.
        if (
            $result->status !== 'SUCCESS' ||
            $result->currency !== 'XAF' ||
            $result->provider !== $payment->method ||
            $result->amount !== (string) $payment->amount ||
            empty($payment->reference) ||
            !hash_equals(
                (string) $payment->reference,
                $result->reference
            ) ||
            empty(trim(
                (string) $result->providerTransactionId
            ))
        ) {
            throw ValidationException::withMessages([
                'verification' =>
                    'Résultat de vérification non conforme.',
            ]);
        }

        // Verrouiller le compte marchand affecté
        // à cette transaction.
        $account = StorePaymentAccount::query()
            ->whereKey($payment->store_payment_account_id)
            ->where('store_id', $order->store_id)
            ->where('method', $payment->method)
            ->where('status', 'ACTIVE')
            ->whereNotNull('verified_at')
            ->whereNotNull('gateway')
            ->whereNotNull('merchant_account_id')
            ->lockForUpdate()
            ->first();

        if (
            $account === null ||
            empty($result->recipientMerchantAccountId) ||
            !hash_equals(
                (string) $account->merchant_account_id,
                $result->recipientMerchantAccountId
            )
        ) {
            throw ValidationException::withMessages([
                'merchant_account' =>
                    'Compte marchand bénéficiaire incorrect.',
            ]);
        }

        
        // Vérifier également le solde de la commande
        // pendant que les verrous SQL sont actifs.
        app(FinalizeOrderPayment::class)
            ->calculateLockedMobileMoneyTotal(
                order: $order,
                payment: $payment
            );

        // Aucun paiement confirmé ici.
        // Aucun mouvement de stock effectué.
        return $payment;

    }
}
