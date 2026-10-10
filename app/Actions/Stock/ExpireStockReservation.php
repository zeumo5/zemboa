<?php

namespace App\Actions\Stock;


use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpireStockReservation
{
    public function execute(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {

            // Première lecture pour identifier la commande.
            $snapshot = StockReservation::query()
                ->whereKey($reservationId)
                ->first();

            if ($snapshot === null) {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'La réservation de stock est introuvable.',
                ]);
            }

            $order = null;

            // Pour les réservations de commande,
            // verrouiller la commande en premier.
            if ($snapshot->reference_type === 'ORDER') {
                $order = Order::query()
                    ->whereKey($snapshot->reference_id)
                    ->lockForUpdate()
                    ->first();

                if ($order === null) {
                    throw ValidationException::withMessages([
                        'order' =>
                            'La commande associée est introuvable.',
                    ]);
                }
            }

            // Verrouiller ensuite la réservation.
            $reservation = StockReservation::query()
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'La réservation de stock est introuvable.',
                ]);
            }

            // Vérifier que le lien n'a pas changé
            // pendant les lectures.
            if ($order !== null) {
                if (
                    $reservation->reference_type !== 'ORDER' ||
                    (int) $reservation->reference_id !== (int) $order->id ||
                    (int) $reservation->store_id !== (int) $order->store_id
                ) {
                    throw ValidationException::withMessages([
                        'reservation' =>
                            'La réservation ne correspond pas à cette commande.',
                    ]);
                }

                // Protéger les commandes ayant reçu un paiement.
                if ($order->payment_status !== 'UNPAID') {
                    throw ValidationException::withMessages([
                        'payment_status' =>
                            'Une réservation liée à une commande ayant reçu un paiement ne peut pas expirer.',
                    ]);
                }
            } elseif ($reservation->reference_type === 'ORDER') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Le lien de la réservation a changé.',
                ]);
            }

            // Vérifier la date d'expiration.
            if (
                $reservation->expires_at === null ||
                $reservation->expires_at->isFuture()
            ) {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Cette réservation n’a pas encore atteint sa date d’expiration.',
                ]);
            }

            // Seules les réservations actives peuvent expirer.
            if ($reservation->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Seule une réservation active peut expirer.',
                ]);
            }

            // Verrouiller le stock correspondant.
            $stockLevel = StockLevel::query()
                ->where(
                    'product_variant_id',
                    $reservation->product_variant_id
                )
                ->lockForUpdate()
                ->first();

            if ($stockLevel === null) {
                throw ValidationException::withMessages([
                    'stock' =>
                        'Le stock associé à cette réservation est introuvable.',
                ]);
            }

            if (
                $stockLevel->reserved_quantity <
                $reservation->quantity
            ) {
                throw ValidationException::withMessages([
                    'stock' =>
                        'Le stock réservé est incohérent avec la réservation.',
                ]);
            }

            // Libérer uniquement le stock réservé.
            $stockLevel->reserved_quantity -= $reservation->quantity;
            $stockLevel->save();

            // Marquer la réservation comme expirée.
            $reservation->status = 'EXPIRED';
            $reservation->released_at = now();
            $reservation->save();
        });
    }
}
