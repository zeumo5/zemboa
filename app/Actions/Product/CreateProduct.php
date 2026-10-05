<?php

namespace App\Actions\Product;

use App\Actions\ProductVariant\CreateProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateProduct
{
    public function __construct(
        private CreateProductVariant $createProductVariant
    ) {
    }

    public function execute(array $data): Product
    {
        $category = Category::query()
            ->find($data['category_id']);

        if ($category === null) {
            throw ValidationException::withMessages([
                'category_id' =>
                    'La catégorie sélectionnée n’appartient pas à cette boutique.',
            ]);
        }

        $defaultVariant = $data['default_variant'] ?? null;

        if ($defaultVariant === null) {
            throw ValidationException::withMessages([
                'default_variant' =>
                    'Une variante par défaut est obligatoire.',
            ]);
        }

        return DB::transaction(function () use ($data, $defaultVariant) {
            // default_variant n'est pas une colonne de products.
            $productData = $data;
            unset($productData['default_variant']);

            $product = Product::create($productData);

            $sku = $defaultVariant['sku'] ?? null;

            if (blank($sku)) {
                $prefix = Str::upper(
                    Str::substr(
                        preg_replace(
                            '/[^A-Za-z0-9]/',
                            '',
                            $product->name
                        ),
                        0,
                        3
                    )
                );

                $prefix = $prefix !== '' ? $prefix : 'PRD';

                do {
                    $sku = $prefix . '-' . Str::upper(Str::random(6));
                } while (
                    ProductVariant::query()
                        ->where('sku', $sku)
                        ->exists()
                );
            }

            $this->createProductVariant->execute([
                'product_id' => $product->id,
                'sku' => $sku,
                'price' => $defaultVariant['price'],
                'is_default' => true,
                'status' => 'ACTIVE',
            ]);

            return $product;
        });
    }
}