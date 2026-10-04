<?php

namespace App\Actions\ProductVariant;

use Illuminate\Support\Carbon;
use App\Models\Product;
use App\Models\ProductVariant;
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
        'promo_price' =>
            'Le prix promotionnel ne peut pas être négatif.',
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

        return ProductVariant::create($data);
    }
}