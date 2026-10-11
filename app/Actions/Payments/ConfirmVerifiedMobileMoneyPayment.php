<?php
namespace App\Actions\Payments;


use App\Models\Payment;
use Illuminate\Validation\ValidationException;
use LogicException;

class ConfirmVerifiedMobileMoneyPayment
{
    public function execute(int $paymentId): void
    {
        // 1. Rechercher le paiement dans la boutique active.
        // Le modèle Payment utilise BelongsToStore.
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->firstOrFail();

        // 2. Accepter uniquement les paiements Mobile Money.
        if (!in_array($payment->method, [
            'MTN_MOMO',
            'ORANGE_MONEY',
        ], true)) {
            throw ValidationException::withMessages([
                'method' =>
                    'Ce paiement n’est pas un paiement Mobile Money.',
            ]);
        }

        // 3. Refuser les paiements déjà traités.
        if ($payment->status !== 'PENDING') {
            throw ValidationException::withMessages([
                'status' =>
                    'Ce paiement a déjà été traité.',
            ]);
        }

        // 4. Protection temporaire :
        // aucune confirmation tant que la vérification
        // fiable et les verrous ne sont pas implémentés.
        throw new LogicException(
            'Confirmation Mobile Money non encore activée.'
        );
    }
}
