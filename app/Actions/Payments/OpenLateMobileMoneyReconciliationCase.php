<?php

namespace App\Actions\Payments;


use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StorePaymentAccount;
use App\Models\MobileMoneyReconciliationCase;
use App\Payments\MobileMoneyVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenLateMobileMoneyReconciliationCase
{

    public function __construct(
        private readonly ReserveMobileMoneyTransactionClaim $reserveTransactionClaim
    ) {}



    public function execute(int $paymentId): MobileMoneyReconciliationCase
    {
        // 1. Exiger un utilisateur authentifié.
        $user = Auth::user();

        if (!$user instanceof User) {
            abort(401, 'Authentification requise.');
        }

        // 2. Vérifier la permission RBAC.
        if (!$user->hasPermission('payments.reconciliation.create')) {
            abort(403, 'Permission de réconciliation refusée.');
        }

        // 3. Retrouver le paiement de la boutique active.
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->firstOrFail();

        // 4. Vérifier explicitement l'appartenance
        // du paiement à la boutique de l'utilisateur.
        if (
            $user->store_id === null ||
            (int) $user->store_id !== (int) $payment->store_id
        ) {
            abort(403, 'Accès interdit à ce paiement.');
        }


        if (
            $payment->status !== 'FAILED' ||
            !in_array($payment->method, [
                'MTN_MOMO',
                'ORANGE_MONEY',
            ], true)
        ) {
            throw ValidationException::withMessages([
                'payment' => 'Paiement non admissible à la réconciliation.',
            ]);
        }

        // 2. Interroger le vérificateur côté serveur.
        // Ne jamais accepter un résultat du navigateur.

        $result = app(MobileMoneyVerifier::class)->verify($payment);


        // 3. Seul un SUCCESS tardif est traité ici.
        if (
            $result->status !== 'SUCCESS' ||
            $result->provider !== $payment->method ||
            $result->currency !== 'XAF' ||
            $result->amount !== (string) $payment->amount ||
            empty($payment->reference) ||
            !hash_equals(
                (string) $payment->reference,
                $result->reference
            ) ||
            trim((string) $result->providerTransactionId) === ''
        ) {
            throw ValidationException::withMessages([
                'verification' =>
                'Résultat tardif invalide ou non confirmé.',
            ]);
        }

        // 4. Verrouiller puis revérifier les données.

        return DB::transaction(function () use (
            $paymentId,
            $result,
            $user
        ) {

            $lookup = Payment::query()
                ->whereKey($paymentId)
                ->firstOrFail();

            $order = Order::query()
                ->whereKey($lookup->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedPayment->order_id !== $order->id ||
                $lockedPayment->store_id !== $order->store_id ||
                $lockedPayment->status !== 'FAILED' ||
                $lockedPayment->method !== $result->provider ||
                $result->amount !== (string) $lockedPayment->amount ||
                !hash_equals(
                    (string) $lockedPayment->reference,
                    $result->reference
                )
            ) {
                throw ValidationException::withMessages([
                    'payment' =>
                    'Le paiement a changé pendant la vérification.',
                ]);
            }

            // 5. Contrôler le bénéficiaire.
            $account = StorePaymentAccount::query()
                ->whereKey($lockedPayment->store_payment_account_id)
                ->where('store_id', $order->store_id)
                ->where('method', $lockedPayment->method)
                ->lockForUpdate()
                ->first();

            if (
                $account === null ||
                empty($account->merchant_account_id) ||
                empty($result->recipientMerchantAccountId) ||
                !hash_equals(
                    (string) $account->merchant_account_id,
                    $result->recipientMerchantAccountId
                )
            ) {
                throw ValidationException::withMessages([
                    'merchant_account' =>
                    'Le bénéficiaire déclaré ne correspond pas.',
                ]);
            }


            // Vérifier si cette transaction externe est
            // déjà associée à un autre paiement.
            //
            // DB::table permet de rechercher dans toutes
            // les boutiques, sans filtre tenant.
            // On récupère uniquement un booléen.

            $transactionAlreadyUsed = DB::table('payments')
                ->where('provider', $result->provider)
                ->where(
                    'provider_transaction_id',
                    $result->providerTransactionId
                )
                ->where('id', '!=', $lockedPayment->id)
                ->exists();

            if ($transactionAlreadyUsed) {
                throw ValidationException::withMessages([
                    'provider_transaction_id' =>
                    'Cette transaction externe est déjà '
                        . 'associée à un autre paiement.',
                ]);
            }
            // Réserver la transaction externe dans le
            // registre commun aux confirmations et
            // aux dossiers de réconciliation.
            //
            // Cette opération utilise la transaction SQL
            // déjà ouverte et les données verrouillées.
            $this->reserveTransactionClaim->execute(
                payment: $lockedPayment,
                provider: $result->provider,
                providerTransactionId: $result->providerTransactionId,
                claimType: 'RECONCILIATION'
            );

            // On n'exige pas que le compte soit encore
            // ACTIVE : il a pu être désactivé depuis
            // la transaction initiale.

            // 6. Éviter plusieurs dossiers pour
            // le même paiement.
            $existing = MobileMoneyReconciliationCase::query()
                ->where('payment_id', $lockedPayment->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (
                    $existing->provider !== $result->provider ||
                    $existing->provider_transaction_id !==
                    $result->providerTransactionId ||
                    $existing->recipient_merchant_account_id !==
                    $result->recipientMerchantAccountId
                ) {
                    throw ValidationException::withMessages([
                        'reconciliation' =>
                        'Un dossier existe avec des preuves différentes.',
                    ]);
                }

                return $existing;
            }

            // 7. Ouvrir le dossier sans toucher au paiement.
            $case = new MobileMoneyReconciliationCase();

            $case->forceFill([
                'store_id' => $order->store_id,
                'payment_id' => $lockedPayment->id,
                'provider' => $result->provider,
                'payment_reference' => $result->reference,
                'provider_transaction_id' =>
                $result->providerTransactionId,
                'recipient_merchant_account_id' =>
                $result->recipientMerchantAccountId,
                'amount' => $result->amount,
                'currency' => $result->currency,
                'original_payment_status' => 'FAILED',
                'reported_provider_status' => 'SUCCESS',
                'status' => 'OPEN',
                'reason' =>
                'Succès signalé après un échec enregistré.',
            ]);


            $case->save();

            // Enregistrer l'ouverture du dossier
            // dans la même transaction SQL.
            DB::table('mobile_money_reconciliation_events')->insert([
                'store_id' => $case->store_id,
                'reconciliation_case_id' => $case->id,
                'actor_user_id' => $user->id,
                'event_type' => 'OPENED',
                'details' => json_encode([
                    'reason' => 'LATE_PROVIDER_SUCCESS',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $case;
        });
    }
}
