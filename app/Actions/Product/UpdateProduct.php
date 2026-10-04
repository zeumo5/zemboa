<?php

namespace App\Actions\Product;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class UpdateProduct
{
    public function execute(Product $product, array $data): Product
    {
        $category = Category::query()
            ->find($data['category_id']);

        if ($category === null) {
            throw ValidationException::withMessages([
                'category_id' => 'La catégorie sélectionnée n’appartient pas à cette boutique.',
            ]);
        }

        $product->update([
            'category_id' => $category->id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'is_featured' => $data['is_featured'] ?? false,
        ]);

        return $product->refresh();
    }
}