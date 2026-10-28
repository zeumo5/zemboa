<?php

namespace App\Http\Controllers\Professional;

use App\Actions\Product\UpdateProduct;
use App\Actions\ProductVariant\UpdateProductVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\SavePromotionRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CatalogAccess;
use App\Support\CatalogPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class PromotionController extends Controller
{
    public function index(Request $request, ?ProductVariant $productVariant = null): View
    {
        $context = CatalogAccess::context($request, 'products.view');
        abort_unless($context['actor']->roles()->whereIn('code', ['STORE_OWNER', 'STORE_MANAGER'])->exists(), 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:active,scheduled,expired,none'],
            'sort' => ['nullable', 'in:newest,price,end'],
            'featured' => ['nullable', 'in:1'],
        ]);
        $at = now();
        $query = ProductVariant::with(['product.images', 'attributeValues.attribute']);
        if (filled($filters['q'] ?? null)) {
            $query->where(fn ($q) => $q->where('sku', 'like', '%'.$filters['q'].'%')
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$filters['q'].'%')));
        }
        if (($filters['featured'] ?? null) === '1') {
            $query->whereHas('product', fn ($p) => $p->where('is_featured', true));
        }
        switch ($filters['state'] ?? '') {
            case 'none': $query->whereNull('promo_price');
                break;
            case 'scheduled': $query->whereNotNull('promo_price')->where('promo_starts_at', '>', $at);
                break;
            case 'expired': $query->whereNotNull('promo_price')->where('promo_ends_at', '<', $at);
                break;
            case 'active':
                $query->whereNotNull('promo_price')
                    ->where(fn ($q) => $q->whereNull('promo_starts_at')->orWhere('promo_starts_at', '<=', $at))
                    ->where(fn ($q) => $q->whereNull('promo_ends_at')->orWhere('promo_ends_at', '>=', $at));
                break;
        }
        $column = match ($filters['sort'] ?? '') {
            'price' => 'price', 'end' => 'promo_ends_at', default => 'id'
        };
        $variants = $query->orderBy($column, $column === 'id' ? 'desc' : 'asc')->orderBy('id')->paginate(12)->withQueryString();
        $selected = $productVariant?->load(['product.images', 'attributeValues.attribute']) ?? $variants->first();
        if ($selected) {
            Gate::authorize('view', $selected);
        }
        $canWrite = CatalogPricing::writable($context['actor']);

        return view('professional.promotions.index', $context + compact('variants', 'selected', 'filters', 'at', 'canWrite'));
    }

    public function update(SavePromotionRequest $request, ProductVariant $productVariant, UpdateProductVariant $action): RedirectResponse
    {
        CatalogAccess::context($request, 'products.update', true);
        Gate::authorize('update', $productVariant);
        CatalogAccess::mutate($productVariant, $request, fn ($variant) => $action->execute($variant, $request->safe()->only(CatalogPricing::FIELDS)));

        return redirect()->route('professional.promotions.edit', $productVariant)->with('success', __('promotions.saved'));
    }

    public function destroy(Request $request, ProductVariant $productVariant, UpdateProductVariant $action): RedirectResponse
    {
        CatalogAccess::context($request, 'products.update', true);
        abort_unless(CatalogPricing::writable($request->user('web')), 403);
        Gate::authorize('update', $productVariant);
        CatalogAccess::mutate($productVariant, $request, fn ($variant) => $action->execute($variant,
            ['promo_price' => null, 'promo_starts_at' => null, 'promo_ends_at' => null]));

        return redirect()->route('professional.promotions.edit', $productVariant)->with('success', __('promotions.cleared'));
    }

    public function featured(Request $request, Product $product, UpdateProduct $action): RedirectResponse
    {
        CatalogAccess::context($request, 'products.update', true);
        abort_unless(CatalogPricing::writable($request->user('web')), 403);
        Gate::authorize('update', $product);
        $data = $request->validate(['is_featured' => ['required', 'boolean']]);
        CatalogAccess::mutate($product, $request, fn ($current) => $action->execute($current,
            array_replace($current->only(['category_id', 'name', 'slug', 'description', 'status']), $data)));

        return redirect()->route('professional.promotions.index')->with('success', __('promotions.saved'));
    }
}
