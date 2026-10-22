<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vues publiques uniquement. Les requêtes multi-boutiques ci-dessous passent
 * par Query Builder pour ne JAMAIS neutraliser la portée tenant des modèles.
 */
final class MarketplaceController extends Controller
{
    /** Public identity only; each setting is joined to its own active store. */
    private function publicStores(): \Illuminate\Database\Eloquent\Builder
    {
        return Store::query()
            ->leftJoin('store_settings as identity', 'identity.store_id', '=', 'stores.id')
            ->where('stores.status', 'ACTIVE')
            ->orderBy('stores.name')
            ->select([
                'stores.id', 'stores.name', 'stores.slug', 'stores.description',
                'stores.slogan', 'stores.address',
                'identity.logo_path as public_logo_path',
                'identity.banner_path as public_banner_path',
                'identity.primary_color as public_primary_color',
            ]);
    }

    public function index(Request $request): View
    {
        $search = $this->search($request);

        $stores = $this->publicStores()->limit(8)->get();

        $products = $this->publicProducts()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $filter) use ($search): void {
                    $filter->where('p.name', 'like', '%' . $search . '%')
                        ->orWhere('s.name', 'like', '%' . $search . '%');
                });
            })
            ->orderByDesc('p.is_featured')
            ->orderByDesc('p.id')
            ->paginate(12)
            ->withQueryString();

        return view('marketplace.index', compact('stores', 'products', 'search'));
    }

    /** Catalogue général de produits publics : mêmes filtres que l'accueil. */
    public function products(Request $request): View
    {
        $search = $this->search($request);

        $products = $this->publicProducts()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $filter) use ($search): void {
                    $filter->where('p.name', 'like', '%' . $search . '%')
                        ->orWhere('s.name', 'like', '%' . $search . '%');
                });
            })
            ->orderByDesc('p.is_featured')
            ->orderByDesc('p.id')
            ->paginate(24)
            ->withQueryString();

        return view('marketplace.products', compact('products', 'search'));
    }

    public function stores(Request $request): View
    {
        $search = $this->search($request);

        $stores = $this->publicStores()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('stores.name', 'like', '%' . $search . '%');
            })
            ->paginate(24)
            ->withQueryString();

        return view('marketplace.stores', compact('stores', 'search'));
    }

    public function shop(Request $request, string $shopSlug): View
    {
        $store = app(TenantContext::class)->store();
        abort_unless($store->slug === $shopSlug && $store->status === 'ACTIVE', 404);
        $settings = $store->settings()->where('store_id', $store->id)->first();
        $page = match ($request->route()->getName()) {
            'marketplace.shops.products' => 'catalog',
            'marketplace.shops.categories' => 'categories',
            'marketplace.shops.about' => 'about',
            'marketplace.shops.services' => 'services',
            default => 'home',
        };
        if (in_array($page, ['about', 'services'], true)) {
            return view('marketplace.merchant.'.$page, compact('store', 'settings'));
        }
        $search = $this->search($request);
        $data = $request->validate(['category' => ['nullable', 'string', 'max:180']]);
        $selectedCategory = $data['category'] ?? '';
        $categories = Category::query()->where('store_id', $store->id)
            ->where('status', 'ACTIVE')->orderBy('name')->get();
        if ($selectedCategory !== '') {
            abort_unless($categories->contains('slug', $selectedCategory), 404);
        }
        $products = $this->publicProducts($store->id)
            ->when($search !== '', fn (Builder $query) => $query->where('p.name', 'like', '%'.$search.'%'))
            ->when($selectedCategory !== '', fn (Builder $query) => $query->where('c.slug', $selectedCategory))
            ->orderByDesc('p.is_featured')->orderByDesc('p.id')
            ->paginate(12)->withQueryString();
        $variantGroups = ProductVariant::query()->where('store_id', $store->id)
            ->where('status', 'ACTIVE')->whereIn('product_id', $products->pluck('id'))
            ->with(['stockLevel', 'attributeValues.attribute'])->orderByDesc('is_default')->orderBy('id')->get()->groupBy('product_id');
        $view = $page === 'home' ? 'marketplace.shop' : 'marketplace.merchant.'.$page;

        return view($view, compact('store', 'settings', 'search', 'categories', 'selectedCategory', 'products', 'variantGroups'));
    }

    public function product(string $shopSlug, int $product): View
    {
        $store = app(TenantContext::class)->store();
        abort_unless($store->slug === $shopSlug && $store->status === 'ACTIVE', 404);
        $item = $this->publicProducts($store->id)->where('p.id', $product)->first();
        abort_if($item === null, 404);
        $settings = $store->settings()->where('store_id', $store->id)->first();
        $variants = ProductVariant::query()->where('store_id', $store->id)
            ->where('product_id', $item->id)->where('status', 'ACTIVE')
            ->with(['stockLevel', 'attributeValues.attribute'])->orderByDesc('is_default')->orderBy('id')->get();
        $images = ProductImage::query()->where('store_id', $store->id)->where('product_id', $item->id)
            ->orderByDesc('is_primary')->orderBy('position')->orderBy('id')->get();

        return view('marketplace.product', compact('store', 'item', 'settings', 'variants', 'images'));
    }

    public function cart(string $shopSlug): View
    {
        $store = app(TenantContext::class)->store();
        abort_unless($store->slug === $shopSlug && $store->status === 'ACTIVE', 404);
        $settings = $store->settings()->where('store_id', $store->id)->first();
        $saved = session('zemboa.cart', []);
        $hasForeignCart = !empty($saved['items']) && (int) ($saved['store_id'] ?? 0) !== $store->id;
        $otherStore = $hasForeignCart
            ? Store::query()->where('status', 'ACTIVE')->find((int) ($saved['store_id'] ?? 0)) : null;

        return view('marketplace.merchant.cart', compact('store', 'settings', 'otherStore', 'hasForeignCart'));
    }

    private function search(Request $request): string
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        return trim((string) ($data['q'] ?? ''));
    }

    /**
     * Une vue publique de produits ne doit contenir que :
     * - une boutique ACTIVE ;
     * - une catégorie ACTIVE appartenant à la MÊME boutique ;
     * - un produit ACTIVE possédant au moins une variante ACTIVE de la boutique.
     * Ni commandes, ni clients, ni données de caisse ne sont interrogés.
     */
    private function publicProducts(?int $storeId = null): Builder
    {
        $priceQuery = DB::table('product_variants as price_variant')
            ->selectRaw(
                'CASE WHEN price_variant.promo_price IS NOT NULL '
                . 'AND (price_variant.promo_starts_at IS NULL OR price_variant.promo_starts_at <= ?) '
                . 'AND (price_variant.promo_ends_at IS NULL OR price_variant.promo_ends_at >= ?) '
                . 'THEN price_variant.promo_price ELSE price_variant.price END',
                [now(), now()]
            )
            ->whereColumn('price_variant.product_id', 'p.id')
            ->whereColumn('price_variant.store_id', 'p.store_id')
            ->where('price_variant.status', 'ACTIVE')
            ->orderByDesc('price_variant.is_default')
            ->orderBy('price_variant.id')
            ->limit(1);

        $imageQuery = DB::table('product_images as photo')
            ->select('photo.path')
            ->whereColumn('photo.product_id', 'p.id')
            ->whereColumn('photo.store_id', 'p.store_id')
            ->orderByDesc('photo.is_primary')
            ->orderBy('photo.position')
            ->orderBy('photo.id')
            ->limit(1);

        // One aggregate joined to the paginated query; no per-card stock queries.
        $cartVariants = DB::table('product_variants as cv')
            ->leftJoin('stock_levels as cs', function ($join): void {
                $join->on('cs.product_variant_id', '=', 'cv.id')->on('cs.store_id', '=', 'cv.store_id');
            })
            ->where('cv.status', 'ACTIVE')
            ->groupBy('cv.store_id', 'cv.product_id')
            ->select('cv.store_id', 'cv.product_id')
            ->selectRaw('COUNT(*) as variant_count, MIN(cv.id) as variant_id')
            ->selectRaw('SUM(CASE WHEN cs.physical_quantity > cs.reserved_quantity THEN 1 ELSE 0 END) as available_count');

        $query = DB::table('products as p')
            ->joinSub($cartVariants, 'cart_variants', function ($join): void {
                $join->on('cart_variants.product_id', '=', 'p.id')->on('cart_variants.store_id', '=', 'p.store_id');
            })
            ->join('stores as s', function ($join): void {
                $join->on('s.id', '=', 'p.store_id')
                    ->where('s.status', '=', 'ACTIVE');
            })
            ->join('categories as c', function ($join): void {
                $join->on('c.id', '=', 'p.category_id')
                    ->on('c.store_id', '=', 'p.store_id')
                    ->where('c.status', '=', 'ACTIVE');
            })
            ->where('p.status', 'ACTIVE')
            ->whereExists(function ($subquery): void {
                $subquery->selectRaw('1')
                    ->from('product_variants as active_variant')
                    ->whereColumn('active_variant.product_id', 'p.id')
                    ->whereColumn('active_variant.store_id', 'p.store_id')
                    ->where('active_variant.status', 'ACTIVE');
            })
            ->select([
                'p.id', 'p.store_id', 'p.name', 'p.slug',
                'p.description', 'p.is_featured',
                'cart_variants.variant_count as cart_variant_count',
                'cart_variants.variant_id as cart_variant_id',
                'cart_variants.available_count as cart_available_count',
                's.name as store_name', 's.slug as store_slug',
                'c.name as category_name',
            ])
            ->selectSub($priceQuery, 'display_price')
            ->selectSub($imageQuery, 'image_path');

        if ($storeId !== null) {
            $query->where('p.store_id', $storeId);
        }

        return $query;
    }
}
