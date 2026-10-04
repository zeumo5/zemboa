<?php

namespace App\Actions\ProductVariant;

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

        return ProductVariant::create($data);
    }
}