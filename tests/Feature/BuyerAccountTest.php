<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuyerAccountTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Buyer account tests require uncached SQLite :memory:.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    private int $sequence = 0;

    private function buyer(string $phone = '690000001'): User
    {
        return User::factory()->create(['is_buyer' => true, 'status' => 'ACTIVE', 'phone' => $phone]);
    }

    private function store(string $slug): Store
    {
        return Store::create(['name' => 'Store '.$slug, 'slug' => $slug, 'status' => 'ACTIVE']);
    }

    private function order(Store $store, ?User $buyer, array $overrides = []): Order
    {
        app(TenantContext::class)->setFromStore($store);
        $n = ++$this->sequence;
        $category = Category::firstOrCreate(['slug' => 'collection'], ['name' => 'Collection', 'status' => 'ACTIVE']);
        $product = Product::firstOrCreate(['slug' => 'item'], ['name' => 'Recorded item', 'category_id' => $category->id, 'status' => 'ACTIVE']);
        $variant = ProductVariant::firstOrCreate(['sku' => 'SKU-'.$store->id], ['product_id' => $product->id, 'price' => '500.00', 'status' => 'ACTIVE']);
        $customer = Customer::firstOrCreate(['phone' => '+237690000001'], ['name' => 'Local customer', 'email' => 'shared@example.test']);
        $order = Order::create(array_replace([
            'customer_id' => $customer->id, 'order_number' => 'TEST-ORDER-'.$n,
            'customer_name' => 'Recorded contact', 'customer_phone' => $customer->phone,
            'customer_email' => 'shared@example.test', 'status' => 'PENDING', 'payment_status' => 'UNPAID',
            'fulfillment_type' => 'PICKUP', 'subtotal' => '1000.00', 'discount_amount' => '0.00', 'delivery_fee' => '0.00', 'total' => '1000.00',
        ], $overrides));
        $order->forceFill(['buyer_user_id' => $buyer?->id])->save();
        $order->items()->create(['product_variant_id' => $variant->id, 'product_name' => 'Snapshot item '.$n,
            'variant_description' => 'Recorded option', 'sku' => $variant->sku,
            'quantity' => 2, 'unit_price' => '500.00', 'final_unit_price' => '500.00', 'line_total' => '1000.00']);

        return $order;
    }

    public function test_all_pages_require_the_buyer_guard_and_reject_professional_only_sessions(): void
    {
        foreach (['/client/compte', '/client/commandes', '/client/commandes/1', '/client/profil'] as $url) {
            $this->get($url)->assertRedirect(route('buyer.login'));
            $this->getJson($url)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(['is_buyer' => false]), 'web');
        $this->get('/client/commandes')->assertRedirect(route('buyer.login'));
    }

    public function test_dashboard_counts_only_owned_orders_across_stores_and_ignores_request_identity(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer('690000002');
        $a = $this->store('alpha');
        $b = $this->store('beta');
        $one = $this->order($a, $buyer);
        $two = $this->order($b, $buyer, ['payment_status' => 'PAID', 'status' => 'COMPLETED']);
        $guest = $this->order($a, null);
        $foreign = $this->order($b, $other);
        $this->actingAs($buyer, 'buyer')->get('/client/compte?buyer_user_id='.$other->id.'&store_id='.$a->id)
            ->assertOk()->assertViewHas('stats', fn ($stats) => (int) $stats->total === 2 && (int) $stats->pending === 1 && (int) $stats->paid === 1)
            ->assertSee($one->order_number)->assertSee($two->order_number)
            ->assertSee('Store alpha')->assertSee('Store beta')->assertDontSee($guest->order_number)->assertDontSee($foreign->order_number)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/client/commandes')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
    }

    public function test_guests_matching_contacts_and_other_buyers_never_gain_order_access(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer('690000002');
        $store = $this->store('alpha');
        $own = $this->order($store, $buyer);
        $guest = $this->order($store, null);
        $foreign = $this->order($store, $other);
        $this->actingAs($buyer, 'buyer')->get('/client/commandes/'.$own->id)->assertOk()->assertSee('Snapshot item 1')->assertSee('Recorded option');
        foreach ([$guest->id, $foreign->id, 99999] as $id) {
            $this->get('/client/commandes/'.$id)->assertNotFound()->assertDontSee('Snapshot item');
        }
        $this->get('/client/commandes')->assertOk()->assertDontSee($guest->order_number)->assertDontSee($foreign->order_number);
    }

    public function test_pagination_empty_states_and_bilingual_profile_are_real(): void
    {
        $buyer = $this->buyer();
        $store = $this->store('alpha');
        $this->actingAs($buyer, 'buyer')->get('/client/compte')->assertOk()->assertSee(__('customer.empty_title'));
        $this->get('/client/commandes')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
        for ($i = 0; $i < 12; $i++) {
            $this->order($store, $buyer);
        }
        $this->get('/client/commandes')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 10 && $orders->total() === 12);
        $this->get('/client/commandes?page=2')->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 2);
        $this->get('/client/commandes?page=invalid')->assertRedirect();
        foreach (['fr', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/client/profil')->assertOk()
                ->assertSee(__('customer.profile', [], $locale))->assertSee($buyer->email)->assertSee('+237690000001')
                ->assertDontSee('name="phone"', false);
        }
    }

    public function test_payment_summary_uses_only_confirmed_payments_for_the_owned_order_and_store(): void
    {
        $buyer = $this->buyer();
        $a = $this->store('alpha');
        $b = $this->store('beta');
        $order = $this->order($a, $buyer, ['payment_status' => 'PARTIALLY_PAID']);
        foreach ([[$a->id, 'CONFIRMED', '400.00'], [$a->id, 'PENDING', '300.00'], [$b->id, 'CONFIRMED', '100.00']] as [$storeId,$status,$amount]) {
            DB::table('payments')->insert(['store_id' => $storeId, 'order_id' => $order->id, 'method' => 'CASH', 'status' => $status, 'amount' => $amount]);
        }
        $this->actingAs($buyer, 'buyer')->get('/client/commandes/'.$order->id)->assertOk()
            ->assertViewHas('paymentTotals', ['paid' => '400.00', 'balance' => '600.00']);
        Order::withoutGlobalScope('tenant')->whereKey($order->id)->update(['payment_status' => 'PAID']);
        $this->get('/client/commandes/'.$order->id)->assertOk()->assertViewHas('paymentTotals', null);
    }

    public function test_suspended_store_history_is_preserved_without_a_public_store_link(): void
    {
        $buyer = $this->buyer();
        $store = $this->store('suspended');
        $order = $this->order($store, $buyer);
        $store->update(['status' => 'SUSPENDED']);
        $this->actingAs($buyer, 'buyer')->get('/client/commandes/'.$order->id)->assertOk()->assertSee($order->order_number)
            ->assertDontSee(route('marketplace.shops.show', ['shopSlug' => $store->slug]), false);
    }

    public function test_super_admin_and_disabled_buyer_cannot_use_this_area(): void
    {
        $admin = $this->buyer();
        $role = Role::firstOrCreate(['code' => 'SUPER_ADMIN'], ['name' => 'Admin']);
        $admin->roles()->attach($role);
        $this->actingAs($admin, 'buyer')->get('/client/compte')->assertRedirect(route('buyer.login'));
        $disabled = $this->buyer('690000002');
        $disabled->forceFill(['status' => 'INACTIVE'])->save();
        $this->actingAs($disabled,'buyer')->get('/client/commandes')->assertRedirect(route('buyer.login'));
    }
}
