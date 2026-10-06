<?php

namespace App\Actions\Stock;

use App\Models\StockLevel;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStockReservation
{
    public function execute(
        int $productVariantId,
        int $quantity
    ): StockReservation {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' =>
                    'La quantité à réserver doit être supérieure à zéro.',
            ]);
        }

        return DB::transaction(function () use (
            $productVariantId,
            $quantity
        ) {
            $stockLevel = StockLevel::query()
                ->where('product_variant_id', $productVariantId)
                ->lockForUpdate()
                ->first();

            if ($stockLevel === null) {
                throw ValidationException::withMessages([
                    'product_variant_id' =>
                        'Le stock de cette variante est introuvable.',
                ]);
            }

            if ($quantity > $stockLevel->availableQuantity()) {
                throw ValidationException::withMessages([
                    'quantity' =>
                        'La quantité demandée dépasse le stock disponible.',
                ]);
            }

            $stockLevel->reserved_quantity += $quantity;
            $stockLevel->save();

            return StockReservation::create([
                'product_variant_id' => $productVariantId,
                'quantity' => $quantity,
                'status' => 'ACTIVE',
            ]);
        });
    }
}