<?php
namespace App\Actions\Payments;



use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    DB::transaction(function () use ($paymentId, $result) {

        // 1. Identifier la commande du paiement.
        $paymentLookup = Payment::query()
            ->whereKey($paymentId)
            ->firstOrFail();

        // 2. Verrouiller la commande en premier.
        $order = Order::query()
            ->whereKey($paymentLookup->order_id)
            ->lockForUpdate()
            ->firstOrFail();

        // 3. Verrouiller le paiement.
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        // 4. Revérifier les données après verrouillage.
        if (
            $payment->order_id !== $order->id ||
            $payment->store_id !== $order->store_id ||
            $payment->status !== 'PENDING' ||
            $payment->reference !== $result->reference ||
            $payment->method !== $result->provider ||
            (string) $payment->amount !== $result->amount
        ) {
            throw ValidationException::withMessages([
                'payment' =>
                    'Le paiement a changé ou ne correspond plus au résultat vérifié.',
            ]);
        }

        // 5. Enregistrer l'échec.
        $payment->status = 'FAILED';
        $payment->save();
    });

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
