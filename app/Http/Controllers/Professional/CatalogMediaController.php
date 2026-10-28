<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\UploadProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CatalogMedia;
use App\Support\CatalogAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CatalogMediaController extends Controller
{
    public function store(UploadProductImageRequest $request, Product $product, CatalogMedia $media): RedirectResponse
    {
        $media->upload($product, $request->file('image'), $request->validated('alt_text'), $request->boolean('is_primary'));

        return redirect()->route('professional.products.edit', $product)->with('success', __('catalog.saved'));
    }

    public function update(Request $request, ProductImage $image): RedirectResponse
    {
        CatalogAccess::context($request, 'products.update', true);
        Gate::authorize('update', $image->product);
        $data = $request->validate(['alt_text' => ['nullable', 'string', 'max:255'], 'position' => ['required', 'integer', 'min:0', 'max:9999'], 'is_primary' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($image, $request, $data) {
            Product::query()->whereKey($image->product_id)->lockForUpdate()->firstOrFail();
            CatalogAccess::mutate($image, $request, function ($current) use ($data, $request) {
                if ($request->boolean('is_primary')) {
                    $current->product->images()->update(['is_primary' => false]);
                }
                $current->update($data);
            });
        });

        return redirect()->route('professional.products.edit', $image->product_id)->with('success', __('catalog.saved'));
    }
}
