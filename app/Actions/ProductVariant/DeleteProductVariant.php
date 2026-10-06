<?php

namespace App\Actions\ProductVariant;

use App\Models\StockReservation;
use App\Models\StockMovement;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class DeleteProductVariant
{
    public function execute(ProductVariant $variant): void
    {
        $variantsCount = ProductVariant::query()
            ->where('product_id', $variant->product_id)
            ->count();

        if ($variantsCount <= 1) {
            throw ValidationException::withMessages([
                'variant' =>
                    'La dernière variante d’un produit ne peut pas être supprimée.',
            ]);
        }

        if ($variant->is_default) {
            throw ValidationException::withMessages([
                'variant' =>
                    'La variante par défaut ne peut pas être supprimée. Définissez d’abord une autre variante par défaut.',
            ]);
        }

        $hasStockHistory = StockMovement::query()
    ->where('product_variant_id', $variant->id)
    ->exists();

if ($hasStockHistory) {
    throw ValidationException::withMessages([
        'variant' =>
            'Cette variante ne peut pas être supprimée car elle possède un historique de mouvements de stock.',
    ]);
}

$hasStockReservations = StockReservation::query()
    ->where('product_variant_id', $variant->id)
    ->exists();

if ($hasStockReservations) {
    throw ValidationException::withMessages([
        'variant' =>
            'Cette variante ne peut pas être supprimée car elle possède un historique de réservations de stock.',
    ]);
}

        $variant->delete();
    }
}