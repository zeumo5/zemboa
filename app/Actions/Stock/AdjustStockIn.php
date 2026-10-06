<?php

namespace App\Actions\Stock;

use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustStockIn
{
    public function __construct(
        private CreateStockMovement $createStockMovement
    ) {
    }

    public function execute(
        int $productVariantId,
        int $quantity,
        ?int $userId = null,
        ?string $reason = null
    ): void {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' =>
                    'La quantité de correction doit être supérieure à zéro.',
            ]);
        }

        DB::transaction(function () use (
            $productVariantId,
            $quantity,
            $userId,
            $reason
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

            $stockLevel->physical_quantity += $quantity;
            $stockLevel->save();

            $this->createStockMovement->execute([
                'product_variant_id' => $productVariantId,
                'type' => 'ADJUSTMENT_IN',
                'quantity' => $quantity,
                'reason' => $reason,
                'created_by' => $userId,
            ]);
        });
    }
}