<?php
namespace App\Payments;



use App\Models\Payment;
use LogicException;

final class FakeMobileMoneyVerifier implements MobileMoneyVerifier
{
    public function __construct(
        private readonly MobileMoneyVerificationResult $fakeResult
    ) {
        $this->ensureTestingEnvironment();
    }

    public function verify(
        Payment $payment
    ): MobileMoneyVerificationResult {
        // Vérifier à nouveau la sécurité à chaque appel.
        $this->ensureTestingEnvironment();

        // Retourner le résultat préparé pour le test.
        // Aucun appel API et aucun transfert d'argent.
        return $this->fakeResult;
    }

    private function ensureTestingEnvironment(): void
    {
        if (!app()->environment('testing')) {
            throw new LogicException(
                'Le simulateur Mobile Money est réservé aux tests.'
            );
        }
    }
}
