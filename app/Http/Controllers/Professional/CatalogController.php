<?php

namespace App\Http\Controllers\Professional;

use App\Actions\Product\CreateProduct;
use App\Actions\Product\UpdateProduct;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\ProductVariant\UpdateProductVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\StoreProductVariantRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProductVariantRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CatalogAccess;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $context = CatalogAccess::context($request, 'products.view');
        Gate::authorize('viewAny', Product::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:ACTIVE,INACTIVE'], 'category_id' => ['nullable', 'integer'], 'sort' => ['nullable', 'in:newest,name']]);
        $query = Product::query()->with(['category', 'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'), $context['actor']->hasPermission('inventory.view') ? 'variants.stockLevel' : 'variants']);
        if (filled($filters['q'] ?? null)) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', '%'.$filters['q'].'%')));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['category_id'] ?? null)) {
            $query->where('category_id', $filters['category_id']);
        }
        $products = $query->orderBy(($filters['sort'] ?? '') === 'name' ? 'name' : 'id', ($filters['sort'] ?? '') === 'name' ? 'asc' : 'desc')->paginate(15)->withQueryString();
        $categories = Category::query()->orderBy('name')->get();

        return view('professional.catalog.index', $context + compact('products', 'categories', 'filters'));
    }

    public function create(Request $request): View
    {
        $context = CatalogAccess::context($request, 'products.create', true);
        Gate::authorize('create', Product::class);

        return view('professional.catalog.product', $context + ['product' => new Product, 'categories' => Category::query()->orderBy('name')->get()]);
    }

    public function edit(Request $request, Product $product): View
    {
        $context = CatalogAccess::context($request, 'products.view');
        Gate::authorize('view', $product);
        $product->load([$context['actor']->hasPermission('inventory.view') ? 'variants.stockLevel' : 'variants', 'variants.attributeValues.attribute', 'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')]);

        return view('professional.catalog.product', $context + ['product' => $product, 'categories' => Category::query()->orderBy('name')->get()]);
    }

    public function store(StoreProductRequest $request, CreateProduct $action): RedirectResponse
    {
        CatalogAccess::context($request, 'products.create', true);
        Gate::authorize('create', Product::class);
        $request->validate(['default_variant.sku' => ['nullable', Rule::unique('product_variants', 'sku')->where('store_id', app(TenantContext::class)->storeId())], 'default_variant.price' => ['numeric', 'max:9999999999.99', 'decimal:0,2']]);
        $product = CatalogAccess::persist(fn () => $action->execute($request->validated()));

        return redirect()->route('professional.products.edit', $product)->with('success', __('catalog.saved'));
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $action): RedirectResponse
    {
        CatalogAccess::context($request, 'products.update', true);
        Gate::authorize('update', $product);
        CatalogAccess::mutate($product, $request, fn ($current) => $action->execute($current, $request->validated()));

        return redirect()->route('professional.products.edit', $product)->with('success', __('catalog.saved'));
    }

    public function storeVariant(StoreProductVariantRequest $request, Product $product, CreateProductVariant $action): RedirectResponse
    {
        CatalogAccess::context($request, 'products.update', true);
        Gate::authorize('update', $product);
        Gate::authorize('create', ProductVariant::class);
        $request->validate(['price' => ['numeric', 'max:9999999999.99', 'decimal:0,2'], 'promo_price' => ['nullable', 'decimal:0,2']]);
        CatalogAccess::persist(fn () => DB::transaction(function () use ($product, $request, $action) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $action->execute($request->validated() + ['product_id' => $product->id]);
        }));

        return redirect()->route('professional.products.edit', $product)->with('success', __('catalog.saved'));
    }

    public function updateVariant(UpdateProductVariantRequest $request, ProductVariant $productVariant, UpdateProductVariant $action): RedirectResponse
    {
        CatalogAccess::context($request, 'products.update', true);
        Gate::authorize('update', $productVariant);
        $request->validate(['price' => ['numeric', 'max:9999999999.99', 'decimal:0,2'], 'promo_price' => ['nullable', 'decimal:0,2']]);
        DB::transaction(function () use ($productVariant, $request, $action) {
            Product::query()->whereKey($productVariant->product_id)->lockForUpdate()->firstOrFail();
            CatalogAccess::mutate($productVariant, $request, fn ($current) => $action->execute($current, $request->validated()));
        });

        return redirect()->route('professional.products.edit', $productVariant->product_id)->with('success', __('catalog.saved'));
    }
}
