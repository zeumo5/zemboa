<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteProduct
{
    public function execute(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $variantIds = $product->variants()
                ->pluck('id');

            $hasStockMovements = StockMovement::query()
                ->whereIn('product_variant_id', $variantIds)
                ->exists();

            if ($hasStockMovements) {
                throw ValidationException::withMessages([
                    'product' =>
                        'Ce produit ne peut pas être supprimé car il possède un historique de stock.',
                ]);
            }

            $hasStockReservations = StockReservation::query()
                ->whereIn('product_variant_id', $variantIds)
                ->exists();

            if ($hasStockReservations) {
                throw ValidationException::withMessages([
                    'product' =>
                        'Ce produit ne peut pas être supprimé car il possède un historique de réservation de stock.',
                ]);
            }

            $product->variants()->delete();

            $product->delete();
        });
    }
}