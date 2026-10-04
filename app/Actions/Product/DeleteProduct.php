<?php

namespace App\Actions\Product;

use App\Models\Product;

class DeleteProduct
{
    public function execute(Product $product): void
    {
        $product->delete();
    }
}