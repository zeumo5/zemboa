<?php
namespace App\Payments;


final readonly class MobileMoneyVerificationResult
{
    public function __construct(
        // SUCCESS, FAILED ou PENDING
        public string $status,

        // Référence interne ZEMBOA
        public string $reference,

        // Montant de la transaction
        public string $amount,

        // Devise : XAF
        public string $currency,

        // Prestataire : MTN_MOMO ou ORANGE_MONEY
        public string $provider,

        // Identifiant externe de la transaction
        public ?string $providerTransactionId = null,
    ) {
    }
}
