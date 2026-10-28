<?php

namespace App\Actions\ProductVariant;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StoreSetting;
use App\Support\CatalogPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductVariant
{
    public function execute(array $data): ProductVariant
    {
        $product = Product::query()
            ->find($data['product_id']);

        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' => 'Le produit sélectionné n’appartient pas à cette boutique.',
            ]);
        }

        CatalogPricing::validate($data);
        $data = CatalogPricing::normalizeDates($data);

        return DB::transaction(function () use ($data, $product) {
            $hasVariants = ProductVariant::query()
                ->where('product_id', $product->id)
                ->exists();

            // La première variante d'un produit devient automatiquement
            // sa variante par défaut.
            if (! $hasVariants) {
                $data['is_default'] = true;
            }

            // Si la nouvelle variante devient celle par défaut,
            // les anciennes ne doivent plus l'être.
            if ($data['is_default'] ?? false) {
                ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('is_default', true)
                    ->update([
                        'is_default' => false,
                    ]);
            }

            $variant = ProductVariant::create($data);

            $lowStockThreshold = (int) (
                StoreSetting::query()->value('low_stock_threshold') ?? 5
            );

            StockLevel::create([
                'product_variant_id' => $variant->id,
                'physical_quantity' => 0,
                'reserved_quantity' => 0,
                'low_stock_threshold' => $lowStockThreshold,
            ]);

            return $variant;
        });
    }
}
