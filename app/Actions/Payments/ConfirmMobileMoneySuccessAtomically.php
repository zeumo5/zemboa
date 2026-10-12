<?php
namespace App\Actions\Payments;


use App\Actions\Stock\ConvertStockReservation;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\MobileMoneyVerifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmMobileMoneySuccessAtomically
{
    public function __construct(
        private readonly MobileMoneyVerifier $verifier,
        private readonly VerifyMobileMoneyPayment $verifyPayment,
        private readonly ValidateLockedMobileMoneySuccess $validateLocked,
        private readonly FinalizeOrderPayment $finalizePayment,
        private readonly ConvertStockReservation $convertStock
    ) {}

    public function execute(int $paymentId): Payment
    {
        // 1. Vérifier auprès du prestataire côté serveur.
        // Ne jamais accepter un résultat fourni par le client.
        $result = $this->verifyPayment->execute(
            paymentId: $paymentId,
            verifier: $this->verifier
        );

        if ($result->status !== 'SUCCESS') {
            throw ValidationException::withMessages([
                'payment' =>
                    'Le prestataire ne confirme pas ce paiement.',
            ]);
        }

        // 2. Toutes les modifications sont atomiques.
        return DB::transaction(function () use (
            $paymentId,
            $result
        ) {
            // Verrouiller et revérifier la commande,
            // le paiement et le compte marchand.
            $payment = $this->validateLocked->execute(
                paymentId: $paymentId,
                result: $result
            );

            // La commande est déjà verrouillée par
            // ValidateLockedMobileMoneySuccess.
            $order = Order::query()
                ->whereKey($payment->order_id)
                ->firstOrFail();

            // 3. Calculer le montant réellement payé.
            $newTotalPaid =
                $this->finalizePayment
                    ->calculateLockedMobileMoneyTotal(
                        order: $order,
                        payment: $payment
                    );

            $orderTotal = $this->finalizePayment
                ->amountToCents((string) $order->total);

            // Un paiement partiel exige une date limite
            // de règlement encore valide.
            if (
                $newTotalPaid < $orderTotal &&
                (
                    $order->balance_due_at === null ||
                    Carbon::parse(
                        $order->balance_due_at
                    )->isPast()
                )
            ) {
                throw ValidationException::withMessages([
                    'balance_due_at' =>
                        'Une date limite future est obligatoire pour un acompte.',
                ]);
            }

            // 4. Enregistrer la transaction externe.
            // L'index UNIQUE MySQL protège des doublons.
            $payment->provider = $result->provider;
            $payment->provider_transaction_id =
                $result->providerTransactionId;

            $payment->status = 'CONFIRMED';
            $payment->confirmed_at = now();
            $payment->save();

            // 5. Mettre à jour la commande.
            if ($newTotalPaid < $orderTotal) {
                // Acompte : conserver le stock réservé.
                $order->payment_status = 'PARTIALLY_PAID';
            } else {
                // Paiement intégral : convertir le stock.
                $this->finalizePayment->convertReservedStock(
                    order: $order,
                    converter: $this->convertStock,
                    userId: null
                );

                $order->payment_status = 'PAID';
            }

            $order->save();

            // Un seul commit SQL pour le paiement,
            // la commande et les mouvements de stock.
            return $payment->refresh();
        });
    }
}
