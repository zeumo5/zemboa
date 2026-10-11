<?php
namespace App\Actions\Payments;


use App\Models\Payment;
use App\Payments\MobileMoneyVerifier;
use App\Payments\MobileMoneyVerificationResult;
use Illuminate\Validation\ValidationException;

class VerifyMobileMoneyPayment
{
    public function execute(
        int $paymentId,
        MobileMoneyVerifier $verifier
    ): MobileMoneyVerificationResult {

        // 1. Retrouver le paiement dans la boutique active.
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->firstOrFail();

        // 2. Autoriser uniquement Mobile Money.
        if (!in_array($payment->method, [
            'MTN_MOMO',
            'ORANGE_MONEY',
        ], true)) {
            throw ValidationException::withMessages([
                'method' => 'Ce paiement n’est pas Mobile Money.',
            ]);
        }

        // 3. Le paiement doit être en attente.
        if ($payment->status !== 'PENDING') {
            throw ValidationException::withMessages([
                'status' => 'Ce paiement a déjà été traité.',
            ]);
        }

        // 4. Une référence interne est obligatoire.
        if (empty($payment->reference)) {
            throw ValidationException::withMessages([
                'reference' => 'Référence de paiement absente.',
            ]);
        }

        // 5. Interroger le vérificateur fourni côté serveur.
        $result = $verifier->verify($payment);

        // 6. Contrôler le statut retourné.
        if (!in_array($result->status, [
            'SUCCESS',
            'FAILED',
            'PENDING',
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Statut du prestataire invalide.',
            ]);
        }

        // 7. Comparer les références.
        if (!hash_equals(
            (string) $payment->reference,
            $result->reference
        )) {
            throw ValidationException::withMessages([
                'reference' => 'Référence de transaction incorrecte.',
            ]);
        }

        // 8. Vérifier le prestataire.
        if ($result->provider !== $payment->method) {
            throw ValidationException::withMessages([
                'provider' => 'Prestataire incorrect.',
            ]);
        }

        // 9. Vérifier la devise.
        if ($result->currency !== 'XAF') {
            throw ValidationException::withMessages([
                'currency' => 'Devise incorrecte.',
            ]);
        }

        // 10. Vérifier le montant exact.
        if ($result->amount !== $payment->amount) {
            throw ValidationException::withMessages([
                'amount' => 'Montant de transaction incorrect.',
            ]);
        }

        // 11. Une réussite doit avoir un identifiant externe.
        if (
            $result->status === 'SUCCESS'
            && (
                $result->providerTransactionId === null
                || trim($result->providerTransactionId) === ''
            )
        ) {
            throw ValidationException::withMessages([
                'provider_transaction_id' =>
                    'Identifiant de transaction manquant.',
            ]);
        }

        // 12. Retourner le résultat vérifié.
        // Aucun paiement n'est confirmé ici.
        return $result;
    }
}
