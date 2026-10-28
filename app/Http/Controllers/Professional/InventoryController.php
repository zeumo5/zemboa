<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Services\StockOperationSubmission;
use App\Support\CatalogAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class InventoryController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'integer'], 'stock_level_id' => ['nullable', 'integer'], 'variant_id' => ['nullable', 'integer'], 'state' => ['nullable', 'in:low,out,ok'], 'sort' => ['nullable', 'in:newest,available,sku'], 'status' => ['nullable', 'in:ACTIVE,CONVERTED,RELEASED,EXPIRED,overdue,not_due'], 'type' => ['nullable', 'in:RECEIPT,SALE,RETURN,ADJUSTMENT_IN,ADJUSTMENT_OUT'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')]]);
    }

    public function index(Request $request): View
    {
        $context = CatalogAccess::context($request, 'inventory.view');
        Gate::authorize('viewAny', StockLevel::class);
        $filters = $this->filters($request);
        $query = StockLevel::query()->with(['productVariant.product.category', 'productVariant.product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')->limit(1)]);
        if (filled($filters['q'] ?? null)) {
            $q = $filters['q'];
            $query->whereHas('productVariant', fn ($v) => $v->where(fn ($v) => $v->where(fn ($v) => $v->where('sku', 'like', '%'.$q.'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$q.'%')))));
        }
        if (filled($filters['category_id'] ?? null)) {
            $query->whereHas('productVariant.product', fn ($q) => $q->where('category_id', $filters['category_id']));
        }
        if (($filters['state'] ?? '') === 'out') {
            $query->whereRaw('physical_quantity-reserved_quantity <= 0');
        }
        if (($filters['state'] ?? '') === 'low') {
            $query->whereRaw('physical_quantity-reserved_quantity <= low_stock_threshold');
        }
        if (($filters['state'] ?? '') === 'ok') {
            $query->whereRaw('physical_quantity-reserved_quantity > low_stock_threshold');
        }
        match ($filters['sort'] ?? 'newest') {
            'available' => $query->orderByRaw('physical_quantity-reserved_quantity asc'), 'sku' => $query->orderBy(ProductVariant::select('sku')->whereColumn('product_variants.id', 'stock_levels.product_variant_id')->limit(1)),default => $query->orderByDesc('id')
        };
        $stocks = $query->paginate(20)->withQueryString();
        $summary = StockLevel::query()->selectRaw('COALESCE(SUM(physical_quantity),0) as physical,COALESCE(SUM(reserved_quantity),0) as reserved,COALESCE(SUM(physical_quantity-reserved_quantity),0) as available,SUM(CASE WHEN physical_quantity-reserved_quantity <= low_stock_threshold THEN 1 ELSE 0 END) as low')->first();

        return view('professional.inventory.index', $context + compact('stocks', 'summary', 'filters') + ['categories' => Category::orderBy('name')->get()]);
    }

    public function show(Request $request, StockLevel $stockLevel): View
    {
        $context = CatalogAccess::context($request, 'inventory.view');
        Gate::authorize('view', $stockLevel);
        $stockLevel->load('productVariant.product.category', 'productVariant.attributeValues.attribute');

        return view('professional.inventory.show', $context + compact('stockLevel'));
    }

    public function form(Request $request, StockOperationSubmission $submission): View
    {
        $context = CatalogAccess::context($request, 'inventory.manage', true);
        $submission->ready();
        $data = $request->validate(['direction' => ['nullable', 'in:in,out'], 'q' => ['nullable', 'string', 'max:100'], 'stock_level_id' => ['nullable', 'integer']]);
        $operation = $request->routeIs('professional.inventory.receive') ? 'receive' : (($data['direction'] ?? 'in') === 'out' ? 'adjust-out' : 'adjust-in');
        $query = StockLevel::with('productVariant.product');
        if (! empty($data['stock_level_id'])) {
            $query->whereKey($data['stock_level_id']);
        } elseif (filled($data['q'] ?? null)) {
            $term = $data['q'];
            $query->whereHas('productVariant', fn ($q) => $q->where(fn ($v) => $v->where('sku', 'like', '%'.$term.'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$term.'%'))));
        }
        $stocks = $query->orderBy('id')->limit(100)->get();
        $token = old('_operation') === $operation && old('operation_token') ? old('operation_token') : $submission->issue($context['actor'], $operation);

        return view('professional.inventory.operation', $context + compact('stocks', 'operation', 'token', 'data'));
    }

    public function submit(StockAdjustmentRequest $request, string $operation, StockOperationSubmission $submission): RedirectResponse
    {
        $context = CatalogAccess::context($request, 'inventory.manage', true);
        $submission->ready();
        $extra = $request->validate(['stock_level_id' => ['required', 'integer'], 'operation_token' => ['required', 'string', 'max:4096']]);
        $stock = StockLevel::findOrFail($extra['stock_level_id']);
        Gate::authorize('manage', $stock);
        $created = $submission->execute($context['actor'], $stock, $operation, (int) $request->validated('quantity'), $request->validated('reason'), $extra['operation_token']);

        return redirect()->route('professional.inventory.show', $stock)->with('success', __($created ? 'inventory.saved' : 'inventory.replayed'));
    }

    public function movements(Request $request): View
    {
        $context = CatalogAccess::context($request, 'inventory.view');
        Gate::authorize('viewAny', StockLevel::class);
        $filters = $this->filters($request);
        $query = StockMovement::with(['productVariant.product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')->limit(1), 'creator:id,name']);
        if (filled($filters['variant_id'] ?? null)) {
            $query->where('product_variant_id', $filters['variant_id']);
        }
        if (filled($filters['type'] ?? null)) {
            $query->where('type', $filters['type']);
        }
        if (filled($filters['q'] ?? null)) {
            $q = $filters['q'];
            $query->whereHas('productVariant', fn ($v) => $v->where(fn ($v) => $v->where('sku', 'like', '%'.$q.'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$q.'%'))));
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
        $movements = $query->orderByDesc('id')->paginate(30)->withQueryString();

        return view('professional.inventory.movements', $context + compact('movements', 'filters'));
    }

    public function reservations(Request $request): View
    {
        $context = CatalogAccess::context($request, 'inventory.view');
        Gate::authorize('viewAny', StockLevel::class);
        $filters = $this->filters($request);
        $query = StockReservation::with(['productVariant.product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')->limit(1)]);
        if (! empty($filters['variant_id'])) {
            $query->where('product_variant_id', $filters['variant_id']);
        }
        if (($filters['status'] ?? '') === 'overdue') {
            $query->where('status', 'ACTIVE')->whereNotNull('expires_at')->where('expires_at', '<=', now());
        } elseif (($filters['status'] ?? '') === 'not_due') {
            $query->where('status', 'ACTIVE')->where('expires_at', '>', now());
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $q = $filters['q'];
            $orderIds = $context['actor']->hasPermission('orders.view') ? Order::select('id')->where('order_number', 'like', '%'.$q.'%') : [];
            $query->where(fn ($q2) => $q2->whereHas('productVariant', fn ($v) => $v->where(fn ($v) => $v->where('sku', 'like', '%'.$q.'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$q.'%'))))->orWhere(fn ($r) => $r->where('reference_type', 'ORDER')->whereIn('reference_id', $orderIds)));
        }
        $reservations = $query->orderByDesc('id')->paginate(30)->withQueryString();
        $orders = $context['actor']->hasPermission('orders.view') ? Order::whereIn('id', $reservations->where('reference_type', 'ORDER')->pluck('reference_id'))->get(['id', 'order_number'])->keyBy('id') : collect();

        return view('professional.inventory.reservations', $context + compact('reservations', 'filters', 'orders'));
    }

    public function reservation(Request $request, StockReservation $reservation): View
    {
        $context = CatalogAccess::context($request, 'inventory.view');
        Gate::authorize('viewAny', StockLevel::class);
        $reservation->load('productVariant.product');
        $order = $reservation->reference_type === 'ORDER' && $context['actor']->hasPermission('orders.view') ? Order::whereKey($reservation->reference_id)->first(['id', 'order_number']) : null;

        return view('professional.inventory.reservation', $context + compact('reservation', 'order'));
    }
}
