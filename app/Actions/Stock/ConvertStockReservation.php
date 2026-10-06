<?php

namespace App\Actions\Stock;

use App\Models\StockLevel;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertStockReservation
{
    public function __construct(
        private CreateStockMovement $createStockMovement
    ) {
    }

    public function execute(
        int $reservationId,
        ?int $userId = null
    ): void {
        DB::transaction(function () use (
            $reservationId,
            $userId
        ) {
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

            if ($reservation->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'reservation' =>
                        'Seule une réservation active peut être convertie en vente.',
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

            if (
                $stockLevel->reserved_quantity
                < $reservation->quantity
            ) {
                throw ValidationException::withMessages([
                    'stock' =>
                        'Le stock réservé est incohérent avec la réservation.',
                ]);
            }

            if (
                $stockLevel->physical_quantity
                < $reservation->quantity
            ) {
                throw ValidationException::withMessages([
                    'stock' =>
                        'Le stock physique est insuffisant pour cette vente.',
                ]);
            }

            $stockLevel->physical_quantity -= $reservation->quantity;
            $stockLevel->reserved_quantity -= $reservation->quantity;
            $stockLevel->save();

            $this->createStockMovement->execute([
                'product_variant_id' =>
                    $reservation->product_variant_id,
                'type' => 'SALE',
                'quantity' => $reservation->quantity,
                'reason' => 'Conversion de réservation en vente',
                'created_by' => $userId,
            ]);

            $reservation->status = 'CONVERTED';
            $reservation->converted_at = now();
            $reservation->save();
        });
    }
}