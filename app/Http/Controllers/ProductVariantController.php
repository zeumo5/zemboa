<?php

namespace App\Http\Controllers;

use App\Actions\ProductVariant\DeleteProductVariant;
use App\Actions\ProductVariant\UpdateProductVariant;
use App\Http\Requests\UpdateProductVariantRequest;
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

    public function show(ProductVariant $productVariant)
{
    Gate::authorize('view', $productVariant);

    return response()->json([
        'id' => $productVariant->id,
        'product_id' => $productVariant->product_id,
        'sku' => $productVariant->sku,
        'price' => $productVariant->price,
        'promo_price' => $productVariant->promo_price,
        'promo_starts_at' => $productVariant->promo_starts_at,
        'promo_ends_at' => $productVariant->promo_ends_at,
        'is_default' => $productVariant->is_default,
        'status' => $productVariant->status,
    ]);
}

public function update(
    UpdateProductVariantRequest $request,
    ProductVariant $productVariant,
    UpdateProductVariant $updateProductVariant
): RedirectResponse {
    Gate::authorize('update', $productVariant);

    $productVariant = $updateProductVariant->execute(
        $productVariant,
        $request->validated()
    );

    return redirect()
        ->route('product-variants.show', $productVariant)
        ->with('success', 'Variante modifiée avec succès.');
}

public function destroy(
    ProductVariant $productVariant,
    DeleteProductVariant $deleteProductVariant
): RedirectResponse {
    Gate::authorize('delete', $productVariant);

    $product = $productVariant->product;

    $deleteProductVariant->execute($productVariant);

    return redirect()
        ->route('products.show', $product)
        ->with('success', 'Variante supprimée avec succès.');
}
}