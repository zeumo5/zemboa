<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Models\{Category, Delivery, Order, Product, ProductVariant, StockLevel, StockReservation, Store, StoreSetting, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB};
use Tests\TestCase;

class ExpireUnpaidOrdersTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshSafeDatabase;
    }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Uncached SQLite :memory: only.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshSafeDatabase();
    }

    private function enable(): void
    {
        config(['order_expiration.enabled' => true, 'order_expiration.connection' => 'sqlite',
            'order_expiration.database' => ':memory:', 'order_expiration.lock_store' => 'file']);
    }

    private function order(string $slug = 'alpha'): Order
    {
        $store = Store::firstOrCreate(['slug' => $slug], ['name' => $slug, 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        StoreSetting::firstOrCreate([], ['pickup_enabled' => true, 'reservation_duration_minutes' => 30]);
        $category = Category::firstOrCreate(['slug' => 'test'], ['name' => 'Test', 'status' => 'ACTIVE']);
        $product = Product::create(['name' => 'Test', 'slug' => 'test-'.bin2hex(random_bytes(4)), 'category_id' => $category->id, 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => bin2hex(random_bytes(8)), 'price' => '1000.00', 'status' => 'ACTIVE']);
        StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 10, 'reserved_quantity' => 0]);
        return app(CreateOrder::class)->execute(customerName: 'Memory test', customerPhone: '690000001', customerEmail: null,
            fulfillmentType: 'PICKUP', items: [['product_variant_id' => $variant->id, 'quantity' => 2]]);
    }

    private function due(Order $order): void
    {
        $order->update(['reservation_expires_at' => now()->subMinute()]);
        StockReservation::where('reference_type', 'ORDER')->where('reference_id', $order->id)->update(['expires_at' => now()->subMinute()]);
    }

    public function test_disabled_command_performs_no_queries_and_no_schedule_is_registered(): void
    {
        config(['order_expiration.enabled' => false]);
        $queries = 0; DB::listen(function () use (&$queries) { $queries++; });
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(0, $queries);
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $this->assertFalse(collect($events)->contains(fn ($event) => str_contains($event->command ?? '', 'orders:expire-unpaid')));
    }

    public function test_expiration_is_tenant_scoped_repeat_safe_and_keeps_physical_stock(): void
    {
        $first = $this->order(); $this->due($first);
        $second = $this->order('beta'); $this->due($second);
        $original = app(TenantContext::class);
        $this->enable();
        $this->artisan('orders:expire-unpaid')->expectsOutput('Expired=2 skipped=0 failed=0')->assertSuccessful();
        $this->assertSame($original, app(TenantContext::class));
        $this->assertDatabaseHas('orders', ['id' => $first->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('orders', ['id' => $second->id, 'status' => 'CANCELLED']);
        $this->assertSame(0, DB::table('stock_levels')->sum('reserved_quantity'));
        $this->assertSame(20, DB::table('stock_levels')->sum('physical_quantity'));
        $this->assertSame(2, DB::table('stock_reservations')->where('status', 'EXPIRED')->count());
        $this->artisan('orders:expire-unpaid')->expectsOutput('Expired=0 skipped=0 failed=0')->assertSuccessful();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paid_partial_future_and_picked_up_orders_remain_untouched(): void
    {
        $future = $this->order();
        $paid = $this->order(); $this->due($paid); $paid->update(['payment_status' => 'PAID']);
        $partial = $this->order(); $this->due($partial); $partial->update(['payment_status' => 'PARTIALLY_PAID']);
        $picked = $this->order(); $this->due($picked);
        $agent = User::factory()->create(['store_id' => $picked->store_id]);
        Delivery::create(['order_id' => $picked->id, 'assigned_to' => $agent->id, 'status' => 'FAILED', 'picked_up_at' => now()->subHour(), 'assigned_at' => now()->subHours(2)]);
        $this->enable();
        $this->artisan('orders:expire-unpaid')->expectsOutput('Expired=0 skipped=0 failed=0')->assertSuccessful();
        $this->assertSame(4, Order::where('status', 'PENDING')->count());
        $this->assertSame(8, DB::table('stock_levels')->sum('reserved_quantity'));
    }

    public function test_overlapping_batch_and_wrong_target_are_refused_before_processing(): void
    {
        $order = $this->order(); $this->due($order); $this->enable();
        $lock = Cache::store('file')->lock('orders-expire:'.hash('sha256', 'sqlite|:memory:'), 600);
        $this->assertTrue($lock->get());
        try { $this->artisan('orders:expire-unpaid')->expectsOutput('Another expiration batch holds the lock. No orders processed.')->assertSuccessful(); }
        finally { $lock->release(); }
        config(['order_expiration.database' => 'wrong-target']);
        $this->artisan('orders:expire-unpaid')->assertFailed();
        $this->assertSame('PENDING', $order->fresh()->status);
        config(['order_expiration.database' => ':memory:']);
        $this->artisan('orders:expire-unpaid', ['--limit' => 0])->assertFailed();
    }

    public function test_inconsistent_reservation_rolls_back_and_does_not_block_other_orders(): void
    {
        $bad = $this->order(); $this->due($bad);
        StockReservation::where('reference_id', $bad->id)->update(['expires_at' => now()->addHour()]);
        $good = $this->order(); $this->due($good); $this->enable();
        $this->artisan('orders:expire-unpaid')->expectsOutput('Expired=1 skipped=1 failed=0')->assertSuccessful();
        $this->assertSame('PENDING', $bad->fresh()->status);
        $this->assertSame('CANCELLED', $good->fresh()->status);
        $this->assertSame(2, DB::table('stock_levels')->sum('reserved_quantity'));
    }

    public function test_demo_activation_is_refused_without_any_query(): void
    {
        $this->enable(); $this->app->detectEnvironment(fn () => 'demo');
        $queries = 0; DB::listen(function () use (&$queries) { $queries++; });
        $this->artisan('orders:expire-unpaid')->assertFailed();
        $this->assertSame(0, $queries);
    }
}
