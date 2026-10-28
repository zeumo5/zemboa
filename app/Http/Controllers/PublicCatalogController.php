<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCatalogController extends Controller
{
    // Informations publiques de la boutique.
    public function shop(string $shopSlug): JsonResponse
    {
        $store = app(TenantContext::class)->store();
        $settings = $store->settings()->first();

        return response()->json([
            'data' => [
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'slogan' => $store->slogan,
                'phone' => $store->phone,
                'whatsapp' => $store->whatsapp,
                'address' => $store->address,
                'logo_path' => $settings?->logo_path,
                'banner_path' => $settings?->banner_path,
                'primary_color' => $settings?->primary_color,
                'opening_hours' => $settings?->opening_hours,
            ],
        ]);
    }

    // Catégories actives de la boutique.
    public function categories(string $shopSlug): JsonResponse
    {
        $categories = Category::query()
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slug',
                'description',
            ]);

        return response()->json([
            'data' => $categories,
        ]);
    }

    // Catalogue public paginé.

    public function products(
        Request $request,
        string $shopSlug
    ): JsonResponse {
        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'category' => [
                'nullable',
                'string',
                'max:150',
            ],
        ]);

        $query = $this->publicProductsQuery();

        // Recherche par nom du produit.
        if (! empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(
                'name',
                'like',
                '%'.$search.'%'
            );
        }

        // Filtrer par catégorie active.
        if (! empty($validated['category'])) {
            $categorySlug = $validated['category'];

            $query->whereHas(
                'category',
                fn (Builder $category) => $category->where(
                    'slug',
                    $categorySlug
                )
            );
        }

        $products = $query
            ->orderBy('id')
            ->paginate(12)
            ->withQueryString();

        $products->through(
            fn (Product $product) => $this->formatProduct($product)
        );

        return response()->json($products);
    }

    // Détails d'un seul produit public.
    public function product(string $shopSlug, int $product): JsonResponse
    {
        $item = $this->publicProductsQuery()
            ->whereKey($product)
            ->firstOrFail();

        return response()->json([
            'data' => $this->formatProduct($item),
        ]);
    }

    // Requête commune pour garantir les mêmes règles.
    private function publicProductsQuery(): Builder
    {
        return Product::query()
            ->where('status', 'ACTIVE')
            ->whereHas(
                'category',
                fn (Builder $query) => $query->where('status', 'ACTIVE')
            )
            ->whereHas(
                'variants',
                fn (Builder $query) => $query->where('status', 'ACTIVE')
            )
            ->with([
                'category',

                'images' => fn ($query) => $query
                    ->orderByDesc('is_primary')
                    ->orderBy('position'),

                'variants' => fn ($query) => $query
                    ->where('status', 'ACTIVE')
                    ->with('stockLevel'),
            ]);
    }

    // Réponse JSON limitée aux données publiques.
    private function formatProduct(Product $product): array
    {
        $at = request()->attributes->get('zemboa.pricing_at');
        if (! $at) {
            $at = now();
            request()->attributes->set('zemboa.pricing_at', $at);
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'is_featured' => (bool) $product->is_featured,

            'category' => [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ],

            'images' => $product->images
                ->map(fn ($image) => [
                    'path' => $image->path,
                    'alt_text' => $image->alt_text,
                    'is_primary' => (bool) $image->is_primary,
                ])
                ->values()
                ->all(),

            'variants' => $product->variants
                ->map(fn ($variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'price' => (string) $variant->price,
                    'effective_price' => (string) $variant->effectivePrice($at),
                    'is_default' => (bool) $variant->is_default,
                    'in_stock' => $variant->stockLevel !== null
                        && $variant->stockLevel
                            ->availableQuantity() > 0,
                ])
                ->values()
                ->all(),
        ];
    }
}
