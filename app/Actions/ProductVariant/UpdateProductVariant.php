<?php

namespace App\Actions\ProductVariant;

use App\Models\ProductVariant;
use App\Support\CatalogPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProductVariant
{
    public function execute(
        ProductVariant $variant,
        array $data
    ): ProductVariant {
        return DB::transaction(function () use ($variant, $data) {
            $variant = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            CatalogPricing::validate(array_replace($variant->only(CatalogPricing::FIELDS), $data));
            $data = CatalogPricing::normalizeDates($data);

            if (
                $variant->is_default
                && array_key_exists('is_default', $data)
                && in_array($data['is_default'], [false, 0, '0'], true)
            ) {
                throw ValidationException::withMessages([
                    'is_default' => 'La variante par défaut ne peut pas être retirée sans définir une autre variante par défaut.',
                ]);
            }

            if ($data['is_default'] ?? false) {
                ProductVariant::query()
                    ->where('product_id', $variant->product_id)
                    ->where('id', '!=', $variant->id)
                    ->where('is_default', true)
                    ->update([
                        'is_default' => false,
                    ]);
            }

            $variant->update($data);

            return $variant->refresh();
        });
    }
}
