<?php

namespace App\Actions\Stock;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class CreateStockMovement
{
    private const ALLOWED_TYPES = [
        'RECEIPT',
        'SALE',
        'RETURN',
        'ADJUSTMENT_IN',
        'ADJUSTMENT_OUT',
    ];

    public function execute(array $data): StockMovement
    {
        $variant = ProductVariant::query()
            ->find($data['product_variant_id']);

        if ($variant === null) {
            throw ValidationException::withMessages([
                'product_variant_id' =>
                    'La variante sélectionnée n’appartient pas à cette boutique.',
            ]);
        }

        if (
            !isset($data['quantity'])
            || !is_numeric($data['quantity'])
            || (int) $data['quantity'] <= 0
        ) {
            throw ValidationException::withMessages([
                'quantity' =>
                    'La quantité du mouvement doit être supérieure à zéro.',
            ]);
        }

        if (
            !isset($data['type'])
            || !in_array($data['type'], self::ALLOWED_TYPES, true)
        ) {
            throw ValidationException::withMessages([
                'type' => 'Le type de mouvement de stock est invalide.',
            ]);
        }

        return StockMovement::create($data);
    }
}