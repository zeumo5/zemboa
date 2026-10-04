<?php

namespace App\Actions\Product;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class CreateProduct
{
    public function execute(array $data): Product
    {
        $category = Category::query()
            ->find($data['category_id']);

        if ($category === null) {
            throw ValidationException::withMessages([
                'category_id' => 'La catégorie sélectionnée n’appartient pas à cette boutique.',
            ]);
        }

        return Product::create($data);
    }
}