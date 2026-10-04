<?php

namespace App\Http\Controllers;

use App\Actions\ProductVariant\CreateProductVariant;
use App\Http\Requests\StoreProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProductVariantController extends Controller
{
    public function store(
        StoreProductVariantRequest $request,
        Product $product,
        CreateProductVariant $createProductVariant
    ): RedirectResponse {
        Gate::authorize('create', ProductVariant::class);

        $data = $request->validated();

        // Le product_id vient de la route et non du formulaire.
        $data['product_id'] = $product->id;

        $variant = $createProductVariant->execute($data);

        return redirect()
            ->route('products.show', $product)
            ->with('success', 'Variante créée avec succès.');
    }
}