<?php

namespace App\Services;

use App\Actions\ProductImage\CreateProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogMedia
{
    public function upload(Product $product, UploadedFile $file, ?string $alt, bool $primary): ProductImage
    {
        $directory = 'stores/'.$product->store_id.'/products/'.$product->id;
        $path = $file->storePublicly($directory, 'public');
        if (! $path) {
            throw ValidationException::withMessages(['image' => __('catalog.storage_failed')]);
        }
        try {
            return DB::transaction(function () use ($product, $path, $alt, $primary) {
                Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $primary = $primary || ! $product->images()->where('is_primary', true)->exists();
                if ($primary) {
                    $product->images()->update(['is_primary' => false]);
                }

                return app(CreateProductImage::class)->execute(['product_id' => $product->id, 'path' => $path, 'alt_text' => $alt, 'position' => (int) $product->images()->max('position') + 1, 'is_primary' => $primary]);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }
}
