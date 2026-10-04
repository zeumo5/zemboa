<?php

namespace App\Http\Controllers;

use App\Actions\Product\CreateProduct;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function store(
    StoreProductRequest $request,
    CreateProduct $createProduct
): RedirectResponse {
    Gate::authorize('create', Product::class);

    $product = $createProduct->execute(
        $request->validated()
    );

    return redirect()
        ->route('products.show', $product)
        ->with('success', 'Produit créé avec succès.');
}

public function show(Product $product)
{
    Gate::authorize('view', $product);

    return response()->json([
        'id' => $product->id,
        'name' => $product->name,
        'slug' => $product->slug,
    ]);
}
}
