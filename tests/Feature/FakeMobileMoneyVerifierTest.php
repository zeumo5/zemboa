<?php
namespace Tests\Feature;


use App\Models\Payment;
use App\Payments\FakeMobileMoneyVerifier;
use App\Payments\MobileMoneyVerificationResult;
use Tests\TestCase;

class FakeMobileMoneyVerifierTest extends TestCase
{
    public function test_fake_verifier_returns_success_result(): void
    {
        // 1. Préparer une réponse fictive du prestataire.
        $result = new MobileMoneyVerificationResult(
            status: 'SUCCESS',
            reference: 'ZM-TEST-001',
            amount: '10000.00',
            currency: 'XAF',
            provider: 'MTN_MOMO',
            providerTransactionId: 'FAKE-MTN-001'
        );

        // 2. Créer notre simulateur.
        $verifier = new FakeMobileMoneyVerifier($result);

        // 3. Créer un objet Payment en mémoire.
        // Aucun enregistrement dans MySQL ou SQLite.
        $payment = new Payment();

        // 4. Demander le résultat fictif.
        $verification = $verifier->verify($payment);

        // 5. Vérifier les informations retournées.
        $this->assertSame('SUCCESS', $verification->status);

        $this->assertSame(
            'ZM-TEST-001',
            $verification->reference
        );

        $this->assertSame(
            '10000.00',
            $verification->amount
        );

        $this->assertSame('XAF', $verification->currency);

        $this->assertSame(
            'MTN_MOMO',
            $verification->provider
        );

        $this->assertSame(
            'FAKE-MTN-001',
            $verification->providerTransactionId
        );
    }

    
public function test_fake_verifier_returns_failed_result(): void
{
    // 1. Préparer une réponse fictive d'échec.
    $result = new MobileMoneyVerificationResult(
        status: 'FAILED',
        reference: 'ZM-TEST-FAILED-001',
        amount: '10000.00',
        currency: 'XAF',
        provider: 'ORANGE_MONEY',
        providerTransactionId: 'FAKE-ORANGE-001'
    );

    // 2. Créer le simulateur.
    $verifier = new FakeMobileMoneyVerifier($result);

    // 3. Créer un paiement uniquement en mémoire.
    $payment = new Payment();

    // 4. Demander la vérification fictive.
    $verification = $verifier->verify($payment);

    // 5. Vérifier la réponse.
    $this->assertSame('FAILED', $verification->status);

    $this->assertSame(
        'ORANGE_MONEY',
        $verification->provider
    );

    $this->assertSame(
        '10000.00',
        $verification->amount
    );

    $this->assertSame('XAF', $verification->currency);

    $this->assertSame(
        'ZM-TEST-FAILED-001',
        $verification->reference
    );

    $this->assertSame(
        'FAKE-ORANGE-001',
        $verification->providerTransactionId
    );
}


public function test_fake_verifier_returns_pending_result(): void
{
    // 1. Préparer une réponse fictive en attente.
    $result = new MobileMoneyVerificationResult(
        status: 'PENDING',
        reference: 'ZM-TEST-PENDING-001',
        amount: '10000.00',
        currency: 'XAF',
        provider: 'MTN_MOMO',
        providerTransactionId: null
    );

    // 2. Créer le simulateur.
    $verifier = new FakeMobileMoneyVerifier($result);

    // 3. Créer un paiement uniquement en mémoire.
    $payment = new Payment();

    // 4. Obtenir le résultat simulé.
    $verification = $verifier->verify($payment);

    // 5. Vérifier les informations retournées.
    $this->assertSame('PENDING', $verification->status);

    $this->assertSame(
        'ZM-TEST-PENDING-001',
        $verification->reference
    );

    $this->assertSame(
        '10000.00',
        $verification->amount
    );

    $this->assertSame('XAF', $verification->currency);

    $this->assertSame(
        'MTN_MOMO',
        $verification->provider
    );

    // Le prestataire n'a pas encore fourni
    // d'identifiant de transaction définitif.
    $this->assertNull(
        $verification->providerTransactionId
    );
}


}
