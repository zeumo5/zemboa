<?php

namespace App\Actions\Payments;


use Illuminate\Auth\Access\AuthorizationException;
use App\Models\User;
use App\Actions\Stock\ConvertStockReservation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmPayment
{
    public function __construct(
        private ConvertStockReservation $convertStockReservation
    ) {
    }

    public function execute(int $paymentId, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($paymentId, $userId) {

            // Identifier la commande dans le contexte de la boutique.
            $paymentLookup = Payment::query()
                ->whereKey($paymentId)
                ->firstOrFail();

            // Toujours verrouiller la commande en premier.
            $order = Order::query()
                ->whereKey($paymentLookup->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $payment->order_id !== $order->id ||
                $payment->store_id !== $order->store_id
            ) {
                throw ValidationException::withMessages([
                    'payment' => 'Le paiement ne correspond pas à cette commande.',
                ]);
            }

            if ($payment->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'status' => 'Ce paiement a déjà été traité.',
                ]);
            }

            if ($order->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'order' => 'Cette commande ne peut plus recevoir de paiement.',
                ]);
            }

            if (!in_array($order->payment_status, [
                'UNPAID',
                'PARTIALLY_PAID',
            ], true)) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Cette commande ne peut plus être payée.',
                ]);
            }

            // Pour l'instant, seule une opération CASH
            // explicitement validée par un caissier est acceptée.
            // Les confirmations Mobile Money seront gérées
            // par une intégration sécurisée avec le prestataire.
            if ($payment->method !== 'CASH') {
                throw ValidationException::withMessages([
                    'method' => 'La confirmation Mobile Money n’est pas encore disponible.',
                ]);
            }

            if ($userId === null) {
                throw ValidationException::withMessages([
                    'user' => 'Un caissier identifié est obligatoire.',
                ]);
            }

            
            // Vérifier que le caissier appartient
            // à la boutique de la commande.
            $cashier = User::query()
                ->whereKey($userId)
                ->where('store_id', $order->store_id)
                ->first();

            if ($cashier === null) {
                throw ValidationException::withMessages([
                    'user' =>
                        'Ce caissier n’appartient pas à cette boutique.',
                ]);
            }

            
            // Vérifier que le caissier est bien
            // l'utilisateur actuellement connecté.
            if (auth()->id() !== $cashier->id) {
                throw new AuthorizationException(
                    'Vous ne pouvez pas confirmer un paiement au nom d’un autre utilisateur.'
                );
            }

            // Vérifier la permission RBAC.
            if (!$cashier->hasPermission('payments.confirm')) {
                throw new AuthorizationException(
                    'Vous n’avez pas la permission de confirmer un paiement.'
                );
            }



            $amountToCents = static function (string $amount): int {
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                    throw ValidationException::withMessages([
                        'amount' => 'Montant invalide.',
                    ]);
                }

                [$whole, $decimal] = array_pad(
                    explode('.', $amount, 2),
                    2,
                    '0'
                );

                return ((int) $whole * 100)
                    + (int) str_pad($decimal, 2, '0');
            };

            $paymentAmount = $amountToCents($payment->amount);
            $orderTotal = $amountToCents($order->total);

            if ($paymentAmount <= 0 || $orderTotal <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Le montant doit être supérieur à zéro.',
                ]);
            }

            $confirmedPayments = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'CONFIRMED')
                ->get(['amount']);

            $alreadyPaid = $confirmedPayments->sum(
                fn (Payment $item) => $amountToCents($item->amount)
            );

            $newTotalPaid = $alreadyPaid + $paymentAmount;

            if ($newTotalPaid > $orderTotal) {
                throw ValidationException::withMessages([
                    'amount' => 'Le paiement dépasse le solde de la commande.',
                ]);
            }

            $cashReceived = $payment->cash_received === null
                ? null
                : $amountToCents($payment->cash_received);

            $changeGiven = $payment->change_given === null
                ? 0
                : $amountToCents($payment->change_given);

            if (
                $cashReceived === null ||
                $cashReceived < $paymentAmount ||
                $changeGiven !== $cashReceived - $paymentAmount
            ) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Le montant reçu ou la monnaie rendue est incorrect.',
                ]);
            }

            $payment->status = 'CONFIRMED';
            $payment->confirmed_at = now();
            $payment->received_by = $userId;
            $payment->save();

            if ($newTotalPaid < $orderTotal) {
                $order->payment_status = 'PARTIALLY_PAID';
            } else {
                $reservations = StockReservation::query()
                    ->where('reference_type', 'ORDER')
                    ->where('reference_id', $order->id)
                    ->where('status', 'ACTIVE')
                    ->get();

                foreach ($reservations as $reservation) {
                    $this->convertStockReservation->execute(
                        $reservation->id,
                        $userId
                    );
                }

                $order->payment_status = 'PAID';
            }

            $order->save();

            return $payment->refresh();
        });
    }
}
