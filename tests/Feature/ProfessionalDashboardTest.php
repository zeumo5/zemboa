<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfessionalDashboardTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Dashboard tests require uncached SQLite :memory:.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    private function store(string $slug): Store
    {
        return Store::create(['name' => 'Store '.$slug, 'slug' => $slug, 'status' => 'ACTIVE']);
    }

    private function employee(Store $store, string $code = 'STORE_OWNER', array $permissions = ['orders.view']): User
    {
        $user = User::factory()->create(['store_id' => $store->id, 'status' => 'ACTIVE', 'email_verified_at' => now()]);
        $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
        foreach ($permissions as $permission) {
            $p = Permission::firstOrCreate(['code' => $permission], ['name' => $permission]);
            $role->permissions()->syncWithoutDetaching([$p->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function order(Store $store, string $reference): Order
    {
        app(TenantContext::class)->setFromStore($store);
        $customer = Customer::firstOrCreate(['phone' => '+237690000001'], ['name' => 'Private '.$reference]);

        return Order::create(['customer_id' => $customer->id, 'order_number' => $reference,
            'customer_name' => $customer->name, 'customer_phone' => $customer->phone,
            'status' => 'PENDING', 'payment_status' => 'UNPAID', 'fulfillment_type' => 'PICKUP',
            'subtotal' => '10000.00', 'total' => '10000.00']);
    }

    public function test_dashboard_is_scoped_despite_request_parameters_and_does_not_report_unpaid_revenue(): void
    {
        $a = $this->store('alpha');
        $b = $this->store('foreign');
        $owner = $this->employee($a);
        $local = $this->order($a, 'OWN-ORDER');
        $this->order($b, 'FOREIGN-ORDER');
        $this->actingAs($owner, 'web')->get('/espace-professionnel?store_id='.$b->id)
            ->assertOk()->assertViewHas('counts', fn ($counts) => (int) $counts->total === 1 && (int) $counts->pending === 1)
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$local->id])
            ->assertSee('OWN-ORDER')->assertSee('10 000 FCFA')->assertDontSee('FOREIGN-ORDER')
            ->assertDontSee('Private FOREIGN-ORDER')->assertSee(__('professional.unpaid_note'))
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_empty_dashboard_and_permission_filtered_modules(): void
    {
        $manager = $this->employee($this->store('empty'), 'STORE_MANAGER');
        $this->actingAs($manager, 'web')->get('/espace-professionnel')->assertOk()
            ->assertSee(__('professional.empty'))->assertViewHas('stock', null)
            ->assertViewHas('reservations', null)->assertViewHas('reservationBreakdown', null)->assertViewHas('lowStock', null)
            ->assertDontSee('href="'.route('stock.index').'"', false)
            ->assertDontSee('href="'.route('customers.index').'"', false)
            ->assertDontSee('href="'.route('store.settings.show').'"', false);
    }

    public function test_buyer_session_does_not_authenticate_the_professional_guard(): void
    {
        $buyer = User::factory()->create(['is_buyer' => true, 'status' => 'ACTIVE']);
        $this->actingAs($buyer, 'buyer');
        Auth::shouldUse('web');
        $this->get('/espace-professionnel')->assertUnauthorized();
    }

    public function test_forbidden_roles_and_missing_permission_are_rejected(): void
    {
        foreach (['CASHIER', 'DELIVERY_AGENT', 'SUPER_ADMIN'] as $code) {
            $actor = $this->employee($this->store(strtolower($code)), $code);
            $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertForbidden();
        }
        $actor = $this->employee($this->store('no-permission'), 'STORE_OWNER', []);
        $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertForbidden();
    }

    public function test_unverified_manager_and_suspended_store_are_rejected(): void
    {
        $store = $this->store('restricted');
        $actor = $this->employee($store, 'STORE_MANAGER');
        $actor->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertForbidden();
        $actor->forceFill(['email_verified_at' => now()])->save();
        $store->update(['status' => 'SUSPENDED']);
        $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertForbidden();
    }

    public function test_inventory_uses_real_available_stock_thresholds_and_tenant_scope(): void
    {
        $a = $this->store('inventory');
        $b = $this->store('foreign-inventory');
        $actor = $this->employee($a, 'STORE_OWNER', ['orders.view', 'inventory.view']);
        foreach ([$a, $b] as $store) {
            app(TenantContext::class)->setFromStore($store);
            $category = Category::create(['name' => 'Collection', 'slug' => 'collection', 'status' => 'ACTIVE']);
            $product = Product::create(['name' => 'Item', 'slug' => 'item', 'category_id' => $category->id, 'status' => 'ACTIVE']);
            $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.$store->id, 'price' => '500', 'status' => 'ACTIVE']);
            StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 10, 'reserved_quantity' => 3, 'low_stock_threshold' => 7]);
            StockReservation::create(['product_variant_id' => $variant->id, 'quantity' => 3, 'status' => 'ACTIVE', 'reference_type' => 'ORDER', 'reference_id' => 1, 'expires_at' => now()->subDay()]);
        }
        $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertOk()
            ->assertViewHas('stock', fn ($stock) => (int) $stock->physical === 10 && (int) $stock->reserved === 3 && (int) $stock->available === 7)
            ->assertViewHas('lowStock', 1)->assertViewHas('reservations', 1);
    }

    public function test_recent_orders_are_limited_and_long_values_are_escaped(): void
    {
        $store = $this->store('recent');
        $actor = $this->employee($store);
        for ($i = 0; $i < 10; $i++) {
            $order = $this->order($store, 'ORDER-'.$i);
        }
        $order->update(['customer_name' => '<script>alert(1)</script>'.str_repeat('Long name ', 30)]);
        $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 8 && $orders->first()->id === $order->id)
            ->assertViewHas('counts', fn ($counts) => (int) $counts->total === 10)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_reservation_deadlines_are_distinguished_without_releasing_stock(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
        $store = $this->store('deadlines');
        $other = $this->store('foreign-deadlines');
        $actor = $this->employee($store, 'STORE_OWNER', ['orders.view', 'inventory.view']);
        foreach ([$store, $other] as $tenant) {
            app(TenantContext::class)->setFromStore($tenant);
            $category = Category::create(['name' => 'Collection', 'slug' => 'collection', 'status' => 'ACTIVE']);
            $product = Product::create(['name' => 'Item', 'slug' => 'item', 'category_id' => $category->id, 'status' => 'ACTIVE']);
            $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.$tenant->id, 'price' => '500', 'status' => 'ACTIVE']);
            StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 20, 'reserved_quantity' => 14, 'low_stock_threshold' => 7]);
            foreach ([['ACTIVE', 2, now()->addHour()], ['ACTIVE', 3, now()], ['ACTIVE', 4, now()->subHour()], ['ACTIVE', 5, null], ['EXPIRED', 90, now()->subDay()], ['RELEASED', 90, now()->subDay()], ['CONVERTED', 90, now()->addDay()]] as [$status,$quantity,$expiry]) {
                StockReservation::create(['product_variant_id' => $variant->id, 'quantity' => $quantity, 'status' => $status, 'expires_at' => $expiry, 'reference_type' => 'ORDER', 'reference_id' => 1]);
            }
        }
        $before = DB::table('stock_levels')->orderBy('id')->get()->toJson();
        $reservationsBefore = DB::table('stock_reservations')->orderBy('id')->get()->toJson();
        $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertOk()
            ->assertViewHas('reservations', 4)
            ->assertViewHas('reservationBreakdown', fn ($rows) => (int) $rows->total === 4 && (int) $rows->units === 14
                && (int) $rows->not_due === 1 && (int) $rows->not_due_units === 2
                && (int) $rows->overdue === 2 && (int) $rows->overdue_units === 7
                && (int) $rows->undated === 1 && (int) $rows->undated_units === 5)
            ->assertViewHas('stock', fn ($stock) => (int) $stock->physical === 20 && (int) $stock->reserved === 14 && (int) $stock->available === 6)
            ->assertSee(__('professional.overdue'))->assertSee(__('professional.undated'));
        $this->assertSame($before, DB::table('stock_levels')->orderBy('id')->get()->toJson());
        $this->assertSame($reservationsBefore, DB::table('stock_reservations')->orderBy('id')->get()->toJson());
    }

    public function test_english_dashboard_uses_the_existing_professional_locale(): void
    {
        $actor = $this->employee($this->store('english'));
        $actor->forceFill(['preferred_locale' => 'en'])->save();
        $this->actingAs($actor, 'web')->get('/espace-professionnel')->assertOk()
            ->assertSee('Recent orders')->assertSee('lang="en"', false);
    }

    public function test_html_login_lands_on_dashboard_without_changing_the_json_login_contract(): void
    {
        $actor = $this->employee($this->store('login'));
        $actor->forceFill(['password' => 'Test-only-password-123'])->save();
        $this->post(route('login.store'), ['email' => $actor->email, 'password' => 'Test-only-password-123'])
            ->assertRedirect(route('professional.dashboard'));
        Auth::guard('web')->logout();
        $this->postJson(route('login.store'), ['email' => $actor->email, 'password' => 'Test-only-password-123'])
            ->assertOk()->assertJsonPath('user.id', $actor->id);
    }
}
