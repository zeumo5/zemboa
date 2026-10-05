<?php

namespace App\Actions\ProductVariant;

use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProductVariant
{
    public function execute(
        ProductVariant $variant,
        array $data
    ): ProductVariant {
        if ($data['price'] < 0) {
    throw ValidationException::withMessages([
        'price' => 'Le prix normal ne peut pas être négatif.',
    ]);
}

if (
    isset($data['promo_price'])
    && $data['promo_price'] !== null
    && $data['promo_price'] < 0
) {
    throw ValidationException::withMessages([
        'promo_price' => 'Le prix promotionnel ne peut pas être négatif.',
    ]);
}

if (
    isset($data['promo_price'])
    && $data['promo_price'] !== null
    && $data['promo_price'] >= $data['price']
) {
    throw ValidationException::withMessages([
        'promo_price' =>
            'Le prix promotionnel doit être inférieur au prix normal.',
    ]);
}
    

    if (
    !empty($data['promo_starts_at'])
    && !empty($data['promo_ends_at'])
) {
    $startsAt = Carbon::parse($data['promo_starts_at']);
    $endsAt = Carbon::parse($data['promo_ends_at']);

    if ($endsAt->lt($startsAt)) {
        throw ValidationException::withMessages([
            'promo_ends_at' =>
                'La date de fin de la promotion doit être postérieure ou égale à la date de début.',
        ]);
    }
}

if (
    $variant->is_default
    && array_key_exists('is_default', $data)
    && in_array($data['is_default'], [false, 0, '0'], true)
) {
    throw ValidationException::withMessages([
        'is_default' =>
            'La variante par défaut ne peut pas être retirée sans définir une autre variante par défaut.',
    ]);
}

return DB::transaction(function () use ($variant, $data) {
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