<?php

namespace App\Support;

use App\Actions\Orders\OrderPriceCalculator;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class PosCart
{
    public function key(User $actor): string
    {
        return 'pos_draft.'.$actor->store_id.'.'.$actor->id;
    }

    public function draft(Request $request, User $actor): array
    {
        return $request->session()->get($this->key($actor), ['items' => [], 'key' => bin2hex(random_bytes(32))]);
    }

    public function quote(array $items, User $actor): array
    {
        PosAccess::authorize($actor);
        ksort($items, SORT_NUMERIC);
        if (count($items) > 100) {
            throw ValidationException::withMessages(['items' => __('pos_interface.invalid_cart')]);
        }
        $variants = ProductVariant::where('store_id', $actor->store_id)->whereIn('id', array_keys($items))
            ->with(['product.images', 'stockLevel', 'attributeValues.attribute'])->get()->keyBy('id');
        $at = now();
        $lines = [];
        foreach ($items as $id => $quantity) {
            $variant = $variants->get($id);
            if (! is_int($quantity) || $quantity < 1 || $quantity > 999 || ! $variant || $variant->status !== 'ACTIVE'
                || ! $variant->product || $variant->product->status !== 'ACTIVE' || (int) $variant->product->store_id !== (int) $actor->store_id
                || ! $variant->stockLevel || $variant->stockLevel->availableQuantity() < $quantity) {
                throw ValidationException::withMessages(['items' => __('pos_interface.unavailable')]);
            }
            $calculator = app(OrderPriceCalculator::class);
            $price = $calculator->toFcfa($variant->effectivePrice($at));
            $image = $variant->product->images->sortBy('position')->first();
            $lines[] = ['product_variant_id' => $variant->id, 'name' => $variant->product->name, 'variant' => self::label($variant),
                'sku' => $variant->sku, 'quantity' => $quantity, 'unit_price' => $variant->price, 'final_unit_price' => $variant->effectivePrice($at),
                'price' => $price, 'line_total' => $price * $quantity, 'available' => $variant->stockLevel->availableQuantity(),
                'image' => $image ? Storage::disk('public')->url($image->path) : null];
        }
        $amounts = app(OrderPriceCalculator::class)->calculate($lines);

        return ['lines' => $lines, 'amounts' => $amounts, 'units' => array_sum(array_column($lines, 'quantity')),
            'quote_hash' => hash('sha256', json_encode([$lines, $amounts], JSON_THROW_ON_ERROR))];
    }

    public static function label(ProductVariant $variant): string
    {
        return $variant->attributeValues->map(fn ($value) => ($value->attribute?->name ? $value->attribute->name.': ' : '').$value->value)->implode(' · ');
    }
}
