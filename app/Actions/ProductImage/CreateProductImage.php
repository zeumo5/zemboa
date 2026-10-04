<?php

namespace App\Actions\ProductImage;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Validation\ValidationException;

class CreateProductImage
{
    public function execute(array $data): ProductImage
{
    // 1. Le produit doit appartenir à la boutique active.
    $product = Product::query()
        ->find($data['product_id']);

    if ($product === null) {
        throw ValidationException::withMessages([
            'product_id' =>
                'Le produit sélectionné n’appartient pas à cette boutique.',
        ]);
    }

    // 2. Si la nouvelle image doit être principale,
    // vérifier qu'il n'en existe pas déjà une.
    if (($data['is_primary'] ?? false) === true) {
        $alreadyHasPrimaryImage = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('is_primary', true)
            ->exists();

        if ($alreadyHasPrimaryImage) {
            throw ValidationException::withMessages([
                'is_primary' =>
                    'Ce produit possède déjà une image principale.',
            ]);
        }
    }

    // 3. Création de l'image dans la boutique active.
    return ProductImage::create($data);
}
}