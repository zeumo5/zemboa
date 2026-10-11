<?php
namespace App\Payments;


use App\Models\Payment;

interface MobileMoneyVerifier
{
    public function verify(
        Payment $payment
    ): MobileMoneyVerificationResult;
}
