<?php

namespace App\Actions\Stock;

use App\Models\StockLevel;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpireStockReservation
{
    public function execute(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {
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

            if (
    $reservation->expires_at === null ||
    $reservation->expires_at->isFuture()
) {
    throw ValidationException::withMessages([
        'reservation' =>
            'Cette réservation n’a pas encore atteint sa date d’expiration.',
    ]);
}

            if ($reservation->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Seule une réservation active peut expirer.',
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
                        'Le stock associé à cette réservation est introuvable.',
                ]);
            }

            if ($stockLevel->reserved_quantity < $reservation->quantity) {
                throw ValidationException::withMessages([
                    'stock' =>
                        'Le stock réservé est incohérent avec la réservation.',
                ]);
            }

            $stockLevel->reserved_quantity -= $reservation->quantity;
            $stockLevel->save();

            $reservation->status = 'EXPIRED';
            $reservation->released_at = now();
            $reservation->save();
        });
    }
}