<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
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
    public function index(Request $request): View
    {
        $search = $this->search($request);

        $stores = Store::query()
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'slug', 'description', 'slogan']);

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

        $stores = Store::query()
            ->where('status', 'ACTIVE')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return view('marketplace.stores', compact('stores', 'search'));
    }

    public function shop(Request $request, string $shopSlug): View
    {
        // Le middleware public.tenant a déjà validé le statut ACTIVE.
        $store = app(TenantContext::class)->store();
        abort_unless($store->slug === $shopSlug, 404);

        $search = $this->search($request);
        $products = $this->publicProducts($store->id)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where('p.name', 'like', '%' . $search . '%');
            })
            ->orderByDesc('p.is_featured')
            ->orderByDesc('p.id')
            ->paginate(12)
            ->withQueryString();

        return view('marketplace.shop', compact('store', 'products', 'search'));
    }

    public function product(string $shopSlug, int $product): View
    {
        $store = app(TenantContext::class)->store();
        abort_unless($store->slug === $shopSlug, 404);

        $item = $this->publicProducts($store->id)
            ->where('p.id', $product)
            ->first();

        abort_if($item === null, 404);

        return view('marketplace.product', compact('store', 'item'));
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

        $query = DB::table('products as p')
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
