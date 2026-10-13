<?php
namespace App\Actions\Payments;


use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ReserveMobileMoneyTransactionClaim
{
    public function execute(
        Payment $payment,
        string $provider,
        string $providerTransactionId,
        string $claimType
    ): void {
        // 1. Exiger une transaction SQL active.
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'La réservation nécessite une transaction SQL.'
            );
        }

        // 2. Vérifier le paiement et le prestataire.
        if (
            !$payment->exists ||
            !$payment->store_id ||
            !in_array(
                $payment->method,
                ['MTN_MOMO', 'ORANGE_MONEY'],
                true
            ) ||
            $provider !== $payment->method
        ) {
            throw ValidationException::withMessages([
                'provider' => 'Prestataire ou paiement invalide.',
            ]);
        }

        // 3. Vérifier le type de réservation et
        // le statut actuel du paiement.
        if (
            !in_array(
                $claimType,
                ['CONFIRMATION', 'RECONCILIATION'],
                true
            ) ||
            (
                $claimType === 'CONFIRMATION' &&
                $payment->status !== 'PENDING'
            ) ||
            (
                $claimType === 'RECONCILIATION' &&
                $payment->status !== 'FAILED'
            )
        ) {
            throw ValidationException::withMessages([
                'claim_type' => 'Réservation non autorisée.',
            ]);
        }

        // 4. Exiger un identifiant externe valide.
        if (
            trim($providerTransactionId) === '' ||
            trim($providerTransactionId) !== $providerTransactionId ||
            strlen($providerTransactionId) > 150
        ) {
            throw ValidationException::withMessages([
                'provider_transaction_id' =>
                    'Identifiant de transaction invalide.',
            ]);
        }

        // 5. Vérifier si ce paiement possède déjà
        // une réservation dans le registre.
        $existing = DB::table('mobile_money_transaction_claims')
            ->where('payment_id', $payment->id)
            ->first();

        if ($existing !== null) {

            // Même réservation : opération idempotente.
            if (
                (int) $existing->store_id ===
                    (int) $payment->store_id &&
                $existing->provider === $provider &&
                $existing->provider_transaction_id ===
                    $providerTransactionId &&
                $existing->claim_type === $claimType
            ) {
                return;
            }

            throw ValidationException::withMessages([
                'provider_transaction_id' =>
                    'Ce paiement possède déjà une autre transaction externe.',
            ]);
        }

        // 6. Réserver l'identifiant externe.
        // La contrainte UNIQUE protège également
        // contre deux insertions simultanées.
        DB::table('mobile_money_transaction_claims')
            ->insert([
                'store_id' => $payment->store_id,
                'payment_id' => $payment->id,
                'provider' => $provider,
                'provider_transaction_id' =>
                    $providerTransactionId,
                'claim_type' => $claimType,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
