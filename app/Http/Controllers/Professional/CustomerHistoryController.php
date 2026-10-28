<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Support\SalesAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $context = SalesAccess::context($request);
        Gate::authorize('viewAny', Customer::class);
        $filters = SalesAccess::filters($request, ['source' => ['nullable', 'in:local,buyer,guest']]);
        $source = $filters['source'] ?? 'local';
        $query = $source === 'local' ? Customer::whereHas('orders')->withCount('orders')->withSum('orders as ordered_total', 'total')->withMax('orders as last_order', 'created_at') : Order::query();
        if ($source === 'buyer') {
            $query->whereNotNull('buyer_user_id')->selectRaw('buyer_user_id, MAX(id) as sample_id, COUNT(*) as orders_count, SUM(total) as ordered_total, MAX(created_at) as last_order')->groupBy('buyer_user_id');
        } elseif ($source === 'guest') {
            $query->whereNull('buyer_user_id')->whereNull('customer_id');
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $columns = $source === 'local' ? ['name', 'phone', 'email'] : ['customer_name', 'customer_phone', 'customer_email'];
            $query->where(function ($q) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }
        $people = ($source === 'buyer' ? $query->orderByDesc('sample_id') : $query->latest('id'))->paginate(20)->withQueryString();
        $samples = $source === 'buyer' ? Order::whereIn('id', $people->pluck('sample_id'))->get(['id', 'customer_name', 'customer_phone', 'customer_email'])->keyBy('id') : collect();

        return view('professional.sales.customers', $context + compact('people', 'source', 'samples', 'filters'));
    }

    public function show(Request $request, string $source, int $identity): View
    {
        $context = SalesAccess::context($request);
        Gate::authorize('viewAny', Customer::class);
        $customer = null;
        $query = Order::query();
        if ($source === 'local') {
            $customer = Customer::whereHas('orders')->findOrFail($identity);
            Gate::authorize('view', $customer);
            $query->where('customer_id', $customer->id);
        } elseif ($source === 'buyer') {
            $query->where('buyer_user_id', $identity);
        } else {
            $query->whereKey($identity)->whereNull('buyer_user_id')->whereNull('customer_id');
        }
        $sample = (clone $query)->latest('id')->firstOrFail();
        $summary = (clone $query)->selectRaw('COUNT(*) as orders_count, SUM(total) as ordered_total')->first();
        $orders = $query->latest('id')->paginate(20)->withQueryString();

        return view('professional.sales.customer', $context + compact('customer', 'sample', 'orders', 'summary', 'source'));
    }
}
