<?php

namespace App\Actions\Category;

use App\Models\Category;

class CreateCategory
{
    public function execute(array $data): Category
    {
        return Category::create($data);
    }
}