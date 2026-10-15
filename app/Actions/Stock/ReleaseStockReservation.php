<?php
namespace App\Actions\Stock;


use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseStockReservation
{
    public function execute(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {

            // Identifier la commande avant les verrouillages.
            $snapshot = StockReservation::query()
                ->whereKey($reservationId)
                ->firstOrFail();

            $order = null;

            // Pour une réservation de commande :
            // verrouiller la commande en premier.
            if ($snapshot->reference_type === 'ORDER') {
                $order = Order::query()
                    ->whereKey($snapshot->reference_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // Verrouiller ensuite la réservation.
            $reservation = StockReservation::query()
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order !== null) {

                // Vérifier la cohérence multiboutiques.
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

                // Ne jamais libérer le stock d'un colis
                // déjà récupéré par le livreur.
                if (
                    $order->delivery()
                        ->whereNotNull('picked_up_at')
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'delivery' =>
                            'Impossible de libérer le stock : le colis a déjà été récupéré.',
                    ]);
                }

            } elseif ($reservation->reference_type === 'ORDER') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Le lien de la réservation a changé.',
                ]);
            }

            if ($reservation->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Seule une réservation active peut être libérée.',
                ]);
            }

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
                        'Le stock associé est introuvable.',
                ]);
            }

            if (
                $stockLevel->reserved_quantity <
                $reservation->quantity
            ) {
                throw ValidationException::withMessages([
                    'stock' =>
                        'Le stock réservé est incohérent.',
                ]);
            }

            // Libération du stock.
            $stockLevel->reserved_quantity -= $reservation->quantity;
            $stockLevel->save();

            $reservation->status = 'RELEASED';
            $reservation->released_at = now();
            $reservation->save();

        }, 3);
    }
}
