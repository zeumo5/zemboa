<?php

namespace App\Http\Controllers\Professional;

use App\Actions\Orders\AdvanceOrderPreparation;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Support\SalesAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderManagementController extends Controller
{
    public function index(Request $request): View
    {
        $context = SalesAccess::context($request);
        Gate::authorize('viewAny', Order::class);
        $filters = SalesAccess::filters($request, ['status' => ['nullable', 'in:PENDING,CANCELLED'], 'payment' => ['nullable', 'in:UNPAID,PARTIALLY_PAID,PAID'],
            'mode' => ['nullable', 'in:DELIVERY,PICKUP,POS'], 'preparation' => ['nullable', 'in:new,preparing,ready']]);
        $schemaReady = AdvanceOrderPreparation::schemaReady();
        $query = Order::withSum('items as units', 'quantity');
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('order_number', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('customer_phone', 'like', $term));
        }
        foreach (['status' => 'status', 'payment' => 'payment_status'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['mode'])) {
            if ($filters['mode'] === 'POS') {
                $query->whereNotNull('pos_session_id');
            } else {
                $query->whereNull('pos_session_id')->where('fulfillment_type', $filters['mode']);
            }
        }
        if (! empty($filters['preparation'])) {
            abort_unless($schemaReady, 503, __('sales.schema'));
            $query->whereNull('pos_session_id')->where('status', 'PENDING');
            match ($filters['preparation']) {
                'new' => $query->whereNull('preparation_started_at')->whereNull('ready_at'),
                'preparing' => $query->whereNotNull('preparation_started_at')->whereNull('ready_at'),
                'ready' => $query->whereNotNull('ready_at'),
            };
        }
        SalesAccess::period($query, $filters);
        $orders = $query->latest('id')->paginate(20)->withQueryString();
        $summary = Order::selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN payment_status='PAID' THEN 1 ELSE 0 END),0) as paid, COALESCE(SUM(CASE WHEN payment_status='UNPAID' THEN 1 ELSE 0 END),0) as unpaid")->first();

        return view('professional.sales.orders', $context + compact('orders', 'filters', 'schemaReady', 'summary'));
    }

    public function show(Request $request, Order $order): View
    {
        $context = SalesAccess::context($request);
        Gate::authorize('viewAny', Order::class);
        $order->load('items.productVariant.attributeValues.attribute', 'delivery', 'customer');
        $schemaReady = AdvanceOrderPreparation::schemaReady();
        if ($schemaReady) {
            $staff = fn ($q) => $q->where('store_id', $context['store']->id)->select('id', 'name');
            $order->load(['preparer' => $staff, 'readyBy' => $staff]);
        }
        $payments = $context['actor']->hasPermission('payments.view') ? $order->payments()->latest('id')->get(['id', 'order_id', 'amount', 'method', 'status', 'reference', 'confirmed_at']) : collect();
        $returns = SalesAccess::allowed($context['actor'], 'returns.view', true) ? ReturnRequest::where('order_id', $order->id)->latest('id')->get(['id', 'order_id', 'status', 'created_at']) : collect();

        return view('professional.sales.order', $context + compact('order', 'payments', 'returns', 'schemaReady'));
    }

    public function prepare(Request $request, Order $order, string $step, AdvanceOrderPreparation $action): RedirectResponse
    {
        $context = SalesAccess::context($request);
        Gate::authorize('prepare', $order);
        $data = $request->validate(['version' => ['required', 'string', 'size:64']]);
        $action->execute($context['actor'], $order->id, $step, $data['version']);

        return redirect()->route('professional.orders.show', $order)->with('success', __('sales.saved'));
    }
}
