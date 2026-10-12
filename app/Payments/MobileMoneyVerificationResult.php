<?php
namespace App\Payments;


final readonly class MobileMoneyVerificationResult
{
    public function __construct(
        public string $status,
        public string $reference,
        public string $amount,
        public string $currency,
        public string $provider,
        public ?string $providerTransactionId = null,
        public ?string $recipientMerchantAccountId = null
    ) {
    }
}
