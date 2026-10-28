<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\OrderPriceCalculator;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreateCashPayment;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\SalesAccess;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegrationAuditPhase21ATest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Only uncached SQLite :memory: allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 25)->setTime(10, 0));
    }

    private function variant(array $prices = []): ProductVariant
    {
        $store = Store::create(['name' => 'Fictional integration store', 'slug' => 'audit-integration', 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        $settings = StoreSetting::create(['pickup_enabled' => true, 'delivery_enabled' => true, 'returns_enabled' => true,
            'return_window_days' => 7, 'allow_partial_payments' => true, 'minimum_deposit_percent' => 50]);
        $settings->forceFill(['commercial_configured' => true])->save();
        $category = Category::create(['name' => 'Fictional category', 'slug' => 'category', 'status' => 'ACTIVE']);
        $product = Product::create(['name' => 'Fictional product', 'slug' => 'product', 'category_id' => $category->id, 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(array_replace(['product_id' => $product->id, 'sku' => 'AUDIT-INTEGRATION',
            'price' => '1000.00', 'promo_price' => '800.00', 'promo_ends_at' => now()->addMinute(),
            'status' => 'ACTIVE', 'is_default' => true], $prices));
        StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 10, 'reserved_quantity' => 0]);

        return $variant;
    }

    private function actor(ProductVariant $variant, string $role): User
    {
        $actor = User::factory()->create(['name' => 'Fictional '.$role, 'email' => strtolower($role).'@example.test',
            'store_id' => $variant->store_id, 'status' => 'ACTIVE', 'is_buyer' => false,
            'email_verified_at' => now(), 'preferred_locale' => 'fr']);
        $actor->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $actor;
    }

    private function cart(ProductVariant $variant): void
    {
        $this->postJson('/shops/audit-integration/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 2])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['customer_name' => 'Fictional audit buyer', 'customer_phone' => '699000001',
            'customer_email' => 'audit-buyer@example.test', 'fulfillment_type' => 'PICKUP'], $extra);
    }

    private function preview(string $page, string $url, ?User $actor = null): void
    {
        if (getenv('ZEMBOA_PHASE21A_PREVIEW') !== '1') {
            return;
        }
        $directory = storage_path('framework/testing/phase21a/preview');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach (['fr', 'en'] as $locale) {
            if ($actor) {
                $actor->forceFill(['preferred_locale' => $locale])->save();
            } else {
                $this->postJson(route('guest.locale.update'), ['locale' => $locale])->assertOk();
            }
            $response = $this->get($url)->assertOk()->assertSee('lang="'.$locale.'"', false);
            $html = str_replace(csrf_token(), 'AUDIT-PREVIEW', $response->getContent());
            $html = preg_replace('/(name="(?:version|checkout_token|operation_token|_token)" value=")[^"]*"/', '$1AUDIT-PREVIEW"', $html);
            file_put_contents($directory.'/'.$page.'-'.$locale.'.html', $html);
        }
    }

    public static function checkoutActors(): array
    {
        return ['guest' => [false], 'buyer' => [true]];
    }

    #[DataProvider('checkoutActors')]
    public function test_expired_offer_to_partial_full_payment_delivery_and_after_sales(bool $connected): void
    {
        $variant = $this->variant();
        $buyer = $connected ? User::factory()->create(['is_buyer' => true, 'store_id' => null]) : null;
        if ($buyer) {
            $this->actingAs($buyer, 'buyer');
        }
        $zone = DeliveryZone::create(['name' => 'Fictional zone', 'city' => 'Douala', 'fee' => '200.00', 'status' => 'ACTIVE']);
        $this->cart($variant);
        $choice = ['fulfillment_type' => 'DELIVERY', 'delivery_zone_id' => $zone->id, 'delivery_address' => 'Fictional address'];
        $this->preview($connected ? 'checkout-buyer' : 'checkout-guest', '/boutiques/audit-integration/commande?'.http_build_query($choice), $buyer);
        $token = $this->getJson('/shops/audit-integration/checkout?'.http_build_query($choice))->assertOk()->json('data.token');
        $this->travel(2)->minutes();
        $changed = $this->postJson('/shops/audit-integration/checkout', $this->data($choice + ['checkout_token' => $token]))
            ->assertConflict()->assertJsonPath('data.requires_confirmation', true);
        $this->assertDatabaseCount('orders', 0);
        $payload = $this->data($choice + ['checkout_token' => $changed->json('data.token')]);
        $created = $this->postJson('/shops/audit-integration/checkout', $payload)->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame($buyer?->id, $order->buyer_user_id);
        $this->assertSame('2200.00', $order->total);
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame(2, $variant->stockLevel->fresh()->reserved_quantity);
        $this->postJson('/shops/audit-integration/checkout', $payload)->assertCreated()->assertJsonPath('data.order_id', $order->id);
        $this->get($created->json('data.confirmation_url'))->assertOk();
        Auth::guard('buyer')->logout();
        $owner = $this->actor($variant, 'STORE_OWNER');
        $manager = $this->actor($variant, 'STORE_MANAGER');
        $agent = $this->actor($variant, 'DELIVERY_AGENT');
        $this->actingAs($owner, 'web');
        $first = app(CreateCashPayment::class)->execute($order->id, '1100.00', '1100.00');
        app(ConfirmPayment::class)->execute($first->id, $owner->id, now()->addDay()->format('Y-m-d H:i:s'));
        $this->assertSame('PARTIALLY_PAID', $order->fresh()->payment_status);
        $this->assertSame(10, $variant->stockLevel->fresh()->physical_quantity);
        $this->assertSame(2, $variant->stockLevel->fresh()->reserved_quantity);
        $last = app(CreateCashPayment::class)->execute($order->id, '1100.00', '1100.00');
        app(ConfirmPayment::class)->execute($last->id, $owner->id);
        try {
            app(ConfirmPayment::class)->execute($last->id, $owner->id);
            $this->fail('A processed cash payment must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame('PAID', $order->fresh()->payment_status);
        $this->assertSame(8, $variant->stockLevel->fresh()->physical_quantity);
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
        $this->assertDatabaseHas('stock_reservations', ['reference_id' => $order->id, 'status' => 'CONVERTED']);
        $this->post(route('professional.orders.prepare', [$order, 'start']), ['version' => SalesAccess::preparationVersion($order->fresh())])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($manager, 'web');
        $this->post(route('professional.orders.prepare', [$order, 'ready']), ['version' => SalesAccess::preparationVersion($order->fresh())])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($owner->id, $order->fresh()->preparation_started_by);
        $this->assertSame($manager->id, $order->fresh()->ready_by);
        $this->preview('orders', route('professional.orders.index'), $manager);
        $this->preview('order', route('professional.orders.show', $order), $manager);
        $this->preview('inventory', route('professional.inventory.index'), $manager);
        $delivery = $this->postJson(route('deliveries.assign', $order->id), ['assigned_to' => $agent->id])->assertCreated()->json('delivery.id');
        $this->actingAs($agent, 'web');
        $this->preview('courier', route('courier.show', $delivery), $agent);
        foreach (['PICKED_UP', 'DELIVERED'] as $status) {
            $this->patchJson(route('deliveries.update-status', $delivery), ['status' => $status])->assertOk();
        }
        $this->patchJson(route('deliveries.update-status', $delivery), ['status' => 'DELIVERED'])->assertUnprocessable();
        $this->actingAs($owner, 'web');
        $form = $this->get(route('professional.returns.create', $order))->assertOk();
        $this->post(route('professional.returns.store', $order), ['version' => $form->viewData('version'), 'reason' => 'Fictional defect',
            'items' => [$order->items()->firstOrFail()->id => 1]])->assertRedirect()->assertSessionHasNoErrors();
        $case = ReturnRequest::firstOrFail();
        foreach (['review' => ['decision' => 'APPROVED'], 'receive' => [], 'inspect' => ['items' => [[
            'return_request_item_id' => $case->items()->firstOrFail()->id, 'restockable_quantity' => 1]]],
            'restock' => ['confirmed_physical' => 1]] as $operation => $data) {
            $this->post(route('professional.returns.update', [$case, $operation]), $data)->assertRedirect()->assertSessionHasNoErrors();
        }
        foreach (['prepare', 'approve', 'simulate'] as $operation) {
            $this->post(route('professional.returns.refund', [$case, $operation]), ['confirmed_simulation' => 1])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('1000.00', Refund::firstOrFail()->amount);
        $this->assertSame('SIMULATED', Refund::firstOrFail()->status);
        $this->assertSame(9, $variant->stockLevel->fresh()->physical_quantity);
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
        $this->assertSame('2200.00', $order->fresh()->total);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_mixed_role_session_must_not_bypass_html_stock_refusal_via_legacy_endpoint(): void
    {
        $variant = $this->variant();
        $owner = $this->actor($variant, 'STORE_OWNER');
        $owner->roles()->attach(Role::where('code', 'DELIVERY_AGENT')->firstOrFail());
        $this->actingAs($owner, 'web');
        $this->get(route('professional.inventory.index'))->assertForbidden();
        $response = $this->postJson(route('stock.receive', $variant->stockLevel), ['quantity' => 1, 'reason' => 'Audit direct request']);
        $this->assertSame(10, $variant->stockLevel->fresh()->physical_quantity);
        $response->assertForbidden();
        $this->assertSame(10, $variant->stockLevel->fresh()->physical_quantity);
    }

    public function test_unverified_manager_session_must_not_mutate_stock_via_legacy_endpoint(): void
    {
        $variant = $this->variant();
        $manager = $this->actor($variant, 'STORE_MANAGER');
        $manager->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($manager, 'web');
        $this->get(route('professional.inventory.index'))->assertForbidden();
        $response = $this->postJson(route('stock.receive', $variant->stockLevel), ['quantity' => 1, 'reason' => 'Audit direct request']);
        $this->assertSame(10, $variant->stockLevel->fresh()->physical_quantity);
        $response->assertForbidden();
        $this->assertSame(10, $variant->stockLevel->fresh()->physical_quantity);
    }

    public function test_checkout_must_reject_delivery_without_the_mandatory_positive_fee(): void
    {
        $variant = $this->variant(['promo_price' => null]);
        $zone = DeliveryZone::create(['name' => 'Fictional free zone', 'city' => 'Douala', 'fee' => '200.00', 'status' => 'ACTIVE']);
        $this->cart($variant);
        $choice = ['fulfillment_type' => 'DELIVERY', 'delivery_zone_id' => $zone->id, 'delivery_address' => 'Fictional address'];
        $token = $this->getJson('/shops/audit-integration/checkout?'.http_build_query($choice))->assertOk()->json('data.token');
        $zone->update(['fee' => '0.00']);
        $this->postJson('/shops/audit-integration/checkout', $this->data($choice + ['checkout_token' => $token]))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
    }

    public function test_checkout_must_reject_totals_exceeding_the_payment_decimal_limit(): void
    {
        $variant = $this->variant(['promo_price' => null]);
        $this->cart($variant);
        $token = $this->getJson('/shops/audit-integration/checkout')->assertOk()->json('data.token');
        $variant->update(['price' => '6000000000.00']);
        $response = $this->postJson('/shops/audit-integration/checkout', $this->data(['checkout_token' => $token]));
        $response->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
    }

    #[DataProvider('checkoutActors')]
    public function test_unconfigured_commerce_is_blocked_in_html_and_on_direct_submission(bool $connected): void
    {
        $variant = $this->variant();
        if ($connected) {
            $this->actingAs(User::factory()->create(['is_buyer' => true, 'store_id' => null]), 'buyer');
        }
        StoreSetting::firstOrFail()->forceFill(['commercial_configured' => false])->save();
        $this->cart($variant);
        $this->get('/boutiques/audit-integration/commande')->assertOk()->assertSee('disabled', false);
        $token = $this->getJson('/shops/audit-integration/checkout')->assertOk()->json('data.token');
        $this->postJson('/shops/audit-integration/checkout', $this->data(['checkout_token' => $token]))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
    }

    public function test_legacy_stock_mutations_preserve_permissions_identity_and_tenant_boundaries(): void
    {
        $variant = $this->variant();
        $owner = $this->actor($variant, 'STORE_OWNER');
        $manager = $this->actor($variant, 'STORE_MANAGER');
        $cashier = $this->actor($variant, 'CASHIER');
        $stock = $variant->stockLevel;
        foreach ([$owner, $manager] as $actor) {
            $this->actingAs($actor, 'web');
            foreach (['stock.receive', 'stock.adjust-in', 'stock.adjust-out'] as $route) {
                $before = $stock->fresh()->physical_quantity;
                $this->postJson(route($route, $stock), ['quantity' => 1, 'reason' => 'Regression'])->assertRedirect();
                $this->assertSame($before + ($route === 'stock.adjust-out' ? -1 : 1), $stock->fresh()->physical_quantity);
            }
        }
        $before = $stock->fresh()->physical_quantity;
        $movements = DB::table('stock_movements')->count();
        foreach (['cashier', 'unverified', 'disabled', 'mixed', 'unknown', 'permission', 'suspended', 'foreign'] as $case) {
            $manager->forceFill(['status' => 'ACTIVE', 'email_verified_at' => now()])->save();
            $manager->roles()->sync([Role::where('code', 'STORE_MANAGER')->value('id')]);
            Role::where('code', 'STORE_MANAGER')->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('code', 'inventory.manage')->value('id')]);
            $store = Store::findOrFail($variant->store_id);
            $store->update(['status' => 'ACTIVE']);
            if ($case === 'unverified') {
                $manager->forceFill(['email_verified_at' => null])->save();
            }
            if ($case === 'disabled') {
                $manager->forceFill(['status' => 'DISABLED'])->save();
            }
            if ($case === 'mixed') {
                $manager->roles()->attach(Role::where('code', 'DELIVERY_AGENT')->value('id'));
            }
            if ($case === 'unknown') {
                $manager->roles()->attach(Role::create(['name' => 'Future role', 'code' => 'FUTURE_ROLE'])->id);
            }
            if ($case === 'permission') {
                Role::where('code', 'STORE_MANAGER')->firstOrFail()->permissions()->detach(Permission::where('code', 'inventory.manage')->value('id'));
            }
            if ($case === 'suspended') {
                $store->update(['status' => 'SUSPENDED']);
            }
            if ($case === 'foreign') {
                $other = Store::create(['name' => 'Other', 'slug' => 'other-audit', 'status' => 'ACTIVE']);
                $manager->forceFill(['store_id' => $other->id])->save();
            }
            $this->actingAs($case === 'cashier' ? $cashier : $manager->fresh(), 'web');
            foreach (['stock.receive', 'stock.adjust-in', 'stock.adjust-out'] as $route) {
                $this->postJson(route($route, $stock), ['quantity' => 1, 'reason' => 'Denied'])->assertStatus($case === 'foreign' ? 404 : 403);
            }
            $this->assertSame($before, DB::table('stock_levels')->where('id', $stock->id)->value('physical_quantity'));
            $this->assertSame($movements, DB::table('stock_movements')->count());
        }
    }

    public function test_legacy_endpoints_reject_ambiguous_and_unverified_professional_sessions(): void
    {
        $variant = $this->variant();
        $actor = $this->actor($variant, 'STORE_MANAGER');
        foreach (['unverified', 'mixed'] as $case) {
            $actor->forceFill(['email_verified_at' => $case === 'unverified' ? null : now()])->save();
            if ($case === 'mixed') {
                $actor->roles()->attach(Role::where('code', 'STORE_OWNER')->value('id'));
            }
            $this->actingAs($actor->fresh(), 'web');
            foreach (['/products/'.$variant->product_id, '/stock', '/orders', '/store/settings', '/deliveries', '/payments/reconciliations'] as $url) {
                $this->getJson($url)->assertForbidden();
            }
            $this->putJson('/products/'.$variant->product_id, ['name' => 'Forbidden'])->assertForbidden();
            $this->patchJson('/store/settings', ['pickup_enabled' => true])->assertForbidden();
            $this->postJson('/orders/1/delivery/assign', ['assigned_to' => $actor->id])->assertForbidden();
        }
        $this->assertSame('Fictional product', $variant->product->fresh()->name);
    }

    #[DataProvider('checkoutActors')]
    public function test_invalid_delivery_fees_are_rejected_before_any_business_write(bool $connected): void
    {
        $variant = $this->variant(['promo_price' => null]);
        if ($connected) {
            $this->actingAs(User::factory()->create(['is_buyer' => true, 'store_id' => null]), 'buyer');
        }
        $this->cart($variant);
        $zone = DeliveryZone::create(['name' => 'Zone', 'city' => 'Douala', 'fee' => '200.00', 'status' => 'ACTIVE']);
        $choice = ['fulfillment_type' => 'DELIVERY', 'delivery_zone_id' => $zone->id, 'delivery_address' => 'Fictional address'];
        $token = $this->getJson('/shops/audit-integration/checkout?'.http_build_query($choice))->assertOk()->json('data.token');
        foreach (['0', '-1', 'invalid', '0.01'] as $fee) {
            DB::table('delivery_zones')->where('id', $zone->id)->update(['fee' => $fee]);
            $this->postJson('/shops/audit-integration/checkout', $this->data($choice + ['checkout_token' => $token]))
                ->assertUnprocessable()->assertJsonValidationErrors('delivery_zone_id');
            try {
                app(CreateOrder::class)->execute('Buyer', '699000001', null, 'DELIVERY', [['product_variant_id' => $variant->id, 'quantity' => 1]], $zone->id, 'Address');
                $this->fail('Direct order action must reject invalid delivery fees.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('delivery_zone_id', $error->errors());
            }
        }
        $this->postJson('/shops/audit-integration/checkout', $this->data(['fulfillment_type' => 'DELIVERY', 'checkout_token' => $token]))->assertUnprocessable();
        foreach (['orders', 'order_items', 'payments', 'customers', 'stock_reservations', 'stock_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
    }

    public function test_monetary_limits_cover_intermediate_totals_fees_rounding_and_large_quantities(): void
    {
        $calculator = app(OrderPriceCalculator::class);
        foreach (['9999999998.00', '9999999999.00', '9999999999.49'] as $price) {
            $result = $calculator->calculate([['unit_price' => $price, 'final_unit_price' => $price, 'quantity' => 1]]);
            $this->assertSame($calculator->toFcfa($price).'.00', $result['total']);
        }
        $result = $calculator->calculate([['unit_price' => '9999999999', 'final_unit_price' => '9999999998', 'quantity' => 1]], '1');
        $this->assertSame('9999999999.00', $result['total']);
        foreach ([
            ['10000000000', '1', 1, '0'],
            ['9999999999.50', '1', 1, '0'],
            ['6000000000', '1', 2, '0'],
            ['9999999999', '9999999999', 1, '1'],
            ['1', '1', PHP_INT_MAX, '0'],
            [str_repeat('9', 80), '1', 1, '0'],
            ['1', '1', 1, '10000000000'],
        ] as [$price, $final, $quantity, $fee]) {
            try {
                $calculator->calculate([['unit_price' => $price, 'final_unit_price' => $final, 'quantity' => $quantity]], $fee);
                $this->fail('An unrepresentable amount must be refused.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('amount', $error->errors());
            }
        }
        try {
            $calculator->calculate(array_fill(0, 2, ['unit_price' => '6000000000', 'final_unit_price' => '1', 'quantity' => 1]));
            $this->fail('A discounted total cannot hide an overflowing subtotal.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('amount', $error->errors());
        }
        foreach ([null, '', '-1', 'invalid', '0', '0.49', '10000000000'] as $fee) {
            try {
                $calculator->deliveryFee($fee);
                $this->fail('Invalid delivery fee.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('delivery_zone_id', $error->errors());
            }
        }
    }

    public function test_missing_or_unconfigured_settings_block_direct_order_creation(): void
    {
        $this->assertFalse((new StoreSetting)->hasCommercialConfiguration());
        $variant = $this->variant();
        foreach (['unconfigured', 'missing'] as $case) {
            if ($case === 'unconfigured') {
                StoreSetting::firstOrFail()->forceFill(['commercial_configured' => false])->save();
            } else {
                StoreSetting::query()->delete();
            }
            try {
                app(CreateOrder::class)->execute('Buyer', '699000001', null, 'PICKUP', [['product_variant_id' => $variant->id, 'quantity' => 1]]);
                $this->fail('Commercial setup is required.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('fulfillment_type', $error->errors());
            }
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('customers', 0);
            $this->assertDatabaseCount('stock_reservations', 0);
        }
    }

    public function test_invalid_html_quotes_fail_without_redirect_loops(): void
    {
        $variant = $this->variant(['promo_price' => null]);
        $this->cart($variant);
        $zone = DeliveryZone::create(['name' => 'Invalid fee', 'city' => 'Douala', 'fee' => '0.00', 'status' => 'ACTIVE']);
        $this->get('/boutiques/audit-integration/commande?fulfillment_type=DELIVERY&delivery_zone_id='.$zone->id)
            ->assertUnprocessable();
        $variant->update(['price' => '6000000000.00']);
        $this->get('/boutiques/audit-integration/commande')->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_direct_order_rejects_overflowing_subtotals_totals_and_merged_quantities(): void
    {
        $variant = $this->variant(['price' => '6000000000.00', 'promo_price' => '1.00']);
        $zone = DeliveryZone::create(['name' => 'Valid fee', 'city' => 'Douala', 'fee' => '1.00', 'status' => 'ACTIVE']);
        foreach (['discounted', 'fee', 'merged'] as $case) {
            if ($case === 'fee') {
                $variant->update(['price' => '9999999999.00', 'promo_price' => null]);
            }
            $items = [['product_variant_id' => $variant->id, 'quantity' => $case === 'discounted' ? 2 : 1]];
            if ($case === 'merged') {
                $items[] = ['product_variant_id' => $variant->id, 'quantity' => PHP_INT_MAX];
            }
            try {
                app(CreateOrder::class)->execute('Buyer', '699000001', null, $case === 'fee' ? 'DELIVERY' : 'PICKUP', $items, $zone->id, 'Address');
                $this->fail('Overflow must be rejected before business writes.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey($case === 'merged' ? 'quantity' : 'amount', $error->errors());
            }
        }
        foreach (['customers', 'orders', 'order_items', 'payments', 'stock_reservations', 'stock_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
    }

    public function test_inactive_store_cannot_submit_a_previously_valid_checkout_intent(): void
    {
        $variant = $this->variant();
        $this->cart($variant);
        $token = $this->getJson('/shops/audit-integration/checkout')->assertOk()->json('data.token');
        foreach (['PENDING', 'SUSPENDED', 'INACTIVE'] as $status) {
            Store::whereKey($variant->store_id)->update(['status' => $status]);
            $this->postJson('/shops/audit-integration/checkout', $this->data(['checkout_token' => $token]))->assertNotFound();
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }
}
