<?php

namespace App\Actions\Category;

use App\Models\Category;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    public function execute(Category $category): void
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' =>
                    'Cette catégorie ne peut pas être supprimée car elle contient encore des produits.',
            ]);
        }

        $category->delete();
    }
}