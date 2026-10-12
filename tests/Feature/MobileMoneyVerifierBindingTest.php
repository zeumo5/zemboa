<?php
namespace Tests\Feature;


use App\Actions\Payments\ProcessMobileMoneyPayment;
use App\Payments\MobileMoneyVerifier;
use LogicException;
use Tests\TestCase;

class MobileMoneyVerifierBindingTest extends TestCase
{
    public function test_no_real_provider_blocks_verifier_resolution(): void
    {
        $this->expectException(LogicException::class);

        $this->expectExceptionMessage(
            'Aucun prestataire Mobile Money réel configuré.'
        );

        app(MobileMoneyVerifier::class);
    }

    public function test_mobile_money_processing_cannot_start_without_provider(): void
    {
        $this->expectException(LogicException::class);

        $this->expectExceptionMessage(
            'Aucun prestataire Mobile Money réel configuré.'
        );

        app(ProcessMobileMoneyPayment::class);
    }
}
