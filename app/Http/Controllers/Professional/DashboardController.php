<?php

declare(strict_types=1);

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\StoreSetting;
use App\Support\SalesAccess;
use App\Support\StoreCustomizationAccess;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $actor = $request->user('web');
        $gate = Gate::forUser($actor);
        $gate->authorize('viewAny', Order::class);
        $store = app(TenantContext::class)->store();
        $settings = StoreSetting::where('store_id', $store->id)->first(['store_id', 'logo_path']);
        $query = Order::where('store_id', $store->id);
        $counts = (clone $query)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending")->first();
        $distribution = (clone $query)->select('status')->selectRaw('COUNT(*) as count')->groupBy('status')->orderBy('status')->get();
        $orders = (clone $query)->select(['id', 'store_id', 'order_number', 'customer_name', 'status', 'payment_status', 'fulfillment_type', 'total', 'created_at'])
            ->withSum('items as units', 'quantity')->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get();
        $canInventory = $gate->allows('viewAny', StockLevel::class);
        $stock = $canInventory ? StockLevel::where('store_id', $store->id)
            ->selectRaw('COALESCE(SUM(physical_quantity),0) as physical, COALESCE(SUM(reserved_quantity),0) as reserved, COALESCE(SUM(physical_quantity - reserved_quantity),0) as available')
            ->first() : null;
        // Same available-quantity rule as StockLevel::isLowStock(), in one aggregate.
        $lowStock = $canInventory ? StockLevel::where('store_id', $store->id)
            ->whereRaw('(physical_quantity - reserved_quantity) <= low_stock_threshold')->count() : null;
        $reservationBreakdown = null;
        if ($canInventory) {
            $cutoff = now();
            $reservationQuery = StockReservation::where('store_id', $store->id)->where('status', 'ACTIVE')
                ->selectRaw('COUNT(*) as total, COALESCE(SUM(quantity), 0) as units');
            foreach (['not_due' => '>', 'overdue' => '<='] as $bucket => $operator) {
                $reservationQuery->selectRaw("COALESCE(SUM(CASE WHEN expires_at $operator ? THEN 1 ELSE 0 END), 0) as $bucket", [$cutoff])
                    ->selectRaw("COALESCE(SUM(CASE WHEN expires_at $operator ? THEN quantity ELSE 0 END), 0) as {$bucket}_units", [$cutoff]);
            }
            $reservationBreakdown = $reservationQuery
                ->selectRaw('COALESCE(SUM(CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END), 0) as undated, COALESCE(SUM(CASE WHEN expires_at IS NULL THEN quantity ELSE 0 END), 0) as undated_units')
                ->first();
        }
        $reservations = $reservationBreakdown === null ? null : (int) $reservationBreakdown->total;
        $modules = [
            ['route' => 'professional.orders.index', 'label' => 'orders', 'allowed' => SalesAccess::allowed($actor, 'orders.view')],
            ['route' => 'professional.products.index', 'label' => 'products', 'allowed' => $actor->hasPermission('products.view')],
            ['route' => 'professional.categories.index', 'label' => 'categories', 'allowed' => $gate->allows('viewAny', Category::class)],
            ['route' => 'professional.inventory.index', 'label' => 'stock', 'allowed' => $canInventory],
            ['route' => 'professional.customers.index', 'label' => 'customers', 'allowed' => SalesAccess::allowed($actor, 'customers.view')],
            ['route' => 'professional.customization.index', 'label' => 'settings', 'allowed' => StoreCustomizationAccess::readable($actor)],
        ];

        return response()->view('professional.dashboard', compact('store', 'settings', 'actor', 'counts', 'orders', 'distribution', 'stock', 'lowStock', 'reservations', 'reservationBreakdown', 'modules'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
