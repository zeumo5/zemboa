<?php
namespace App\Actions\Payments;


use App\Payments\MobileMoneyVerifier;
use App\Payments\MobileMoneyVerificationResult;
use LogicException;

class ProcessMobileMoneyPayment
{
    public function __construct(
        private readonly VerifyMobileMoneyPayment $verifyPayment,
        private readonly MobileMoneyVerifier $verifier
    ) {
    }

    public function execute(
        int $paymentId
    ): MobileMoneyVerificationResult {

        // 1. Vérifier le paiement auprès du prestataire.
        // Le vérificateur doit être fourni par le serveur,
        // jamais directement par le navigateur du client.
        $result = $this->verifyPayment->execute(
            paymentId: $paymentId,
            verifier: $this->verifier
        );

        // 2. Paiement toujours en attente.
        if ($result->status === 'PENDING') {
            return $result;
        }

        // 3. Paiement échoué.
        // Le changement en base sera développé ensuite.
        if ($result->status === 'FAILED') {
            return $result;
        }

        // 4. Paiement annoncé comme réussi.
        // Ne jamais confirmer avant d'avoir implémenté
        // le traitement atomique avec verrous MySQL.
        if ($result->status === 'SUCCESS') {
            throw new LogicException(
                'Confirmation Mobile Money non encore activée.'
            );
        }

        // Protection supplémentaire.
        throw new LogicException(
            'Résultat Mobile Money inattendu.'
        );
    }
}
