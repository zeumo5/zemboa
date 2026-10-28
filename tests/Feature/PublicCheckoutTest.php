<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicCheckoutTest extends TestCase
{
    public function test_pos_anonymous_flag_cannot_remove_online_customer_requirements(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $token = $this->token();
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token, 'customer_name' => '', 'customer_phone' => '', 'allowAnonymousPos' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_name', 'customer_phone']);
        $this->assertDatabaseCount('orders', 0);
    }

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

    private function shop(string $slug = 'alpha'): ProductVariant
    {
        $store = Store::create(['name' => 'Test '.$slug, 'slug' => $slug, 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        $settings = StoreSetting::create(['pickup_enabled' => true, 'delivery_enabled' => true, 'reservation_duration_minutes' => 30]);
        $settings->forceFill(['commercial_configured' => true])->save();
        $category = Category::create(['name' => 'Test', 'slug' => 'test', 'status' => 'ACTIVE']);
        $product = Product::create(['name' => 'Test article', 'slug' => 'article', 'category_id' => $category->id, 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'TEST-'.$slug, 'status' => 'ACTIVE', 'price' => '5000.00', 'is_default' => true]);
        StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 10, 'reserved_quantity' => 0]);

        return $variant;
    }

    private function cart(ProductVariant $variant, int $quantity = 2): void
    {
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $variant->id, 'quantity' => $quantity])->assertOk();
        // Laravel's HTTP test client does not automatically retain browser cookies.
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
    }

    private function data(array $extra = []): array
    {
        return array_replace(['customer_name' => 'Guest test', 'customer_phone' => '690000001', 'fulfillment_type' => 'PICKUP'], $extra);
    }

    private function token(): string
    {
        return $this->getJson('/shops/alpha/checkout')->assertOk()->json('data.token');
    }

    public function test_guest_html_checkout_and_session_only_receipt_are_real_and_unpaid(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $token = $this->token();
        $this->get('/boutiques/alpha/commande')->assertOk()->assertSee('checkout_token')->assertSee('FCFA');
        $response = $this->post('/boutiques/alpha/commande', $this->data(['checkout_token' => $token]));
        $response->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('UNPAID')->assertHeader('Cache-Control', 'no-store, private');
        $order = Order::firstOrFail();
        $this->assertNull($order->buyer_user_id);
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame('10000.00', $order->total);
        $this->assertSame(10, $variant->stockLevel->fresh()->physical_quantity);
        $this->assertSame(2, $variant->stockLevel->fresh()->reserved_quantity);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_reservations', 1);
        session()->migrate(true);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get($response->headers->get('Location'))->assertNotFound();
    }

    public function test_replay_after_lost_session_save_never_duplicates_order_or_stock(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $token = $this->token();
        $oldCart = session('zemboa.cart');
        $data = $this->data(['checkout_token' => $token]);
        $first = $this->postJson('/shops/alpha/checkout', $data)->assertCreated();
        $this->withSession(['zemboa.cart' => $oldCart]);
        $this->postJson('/shops/alpha/checkout', $data)->assertCreated()->assertJsonPath('data.order_id', $first->json('data.order_id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame(2, $variant->stockLevel->fresh()->reserved_quantity);
        $this->assertNull(session('zemboa.cart'));
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token, 'customer_name' => 'Changed']))->assertStatus(409);
    }

    public function test_legacy_json_retry_uses_durable_cart_revision_and_never_clears_new_cart(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $this->token();
        $old = session('zemboa.cart');
        $first = $this->postJson('/shops/alpha/checkout', $this->data())->assertCreated();
        $this->withSession(['zemboa.cart' => $old]);
        $this->postJson('/shops/alpha/checkout', $this->data())->assertCreated()->assertJsonPath('data.order_id', $first->json('data.order_id'));
        $oldToken = session('zemboa.checkout.'.$variant->store_id);
        $this->cart($variant, 1);
        $newCart = session('zemboa.cart');
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $oldToken]))->assertCreated();
        $this->assertSame($newCart, session('zemboa.cart'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_buyer_owns_only_new_order_without_claiming_customer_history(): void
    {
        $variant = $this->shop();
        $customer = Customer::create(['name' => 'Historical contact', 'phone' => '+237690000001']);
        $buyer = User::factory()->create(['is_buyer' => true, 'phone' => '690000001']);
        $this->actingAs($buyer, 'buyer');
        $this->cart($variant);
        $this->get('/boutiques/alpha/commande')->assertOk()->assertSee(e($buyer->name), false);
        $first = $this->postJson('/shops/alpha/checkout', $this->data(['buyer_user_id' => 999, 'total' => '1']))->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame($buyer->id, $order->buyer_user_id);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Historical contact', $customer->fresh()->name);
        $this->assertSame('10000.00', $order->total);
        $this->assertArrayNotHasKey('checkout_actor_hash', $order->toArray());
        $other = User::factory()->create(['is_buyer' => true]);
        $this->actingAs($other, 'buyer');
        $this->get($first->json('data.confirmation_url'))->assertNotFound();
    }

    public function test_delivery_quote_uses_real_zone_fee_and_rejects_foreign_zone(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $zone = DeliveryZone::create(['name' => 'Test zone', 'city' => 'Douala', 'fee' => '1000.00', 'status' => 'ACTIVE']);
        $this->getJson('/shops/alpha/checkout?fulfillment_type=DELIVERY&delivery_zone_id='.$zone->id)
            ->assertOk()->assertJsonPath('data.total.total', '11000.00');
        $this->postJson('/shops/alpha/checkout', $this->data(['fulfillment_type' => 'DELIVERY', 'delivery_zone_id' => $zone->id,
            'delivery_address' => 'Demo address', 'delivery_fee' => 0]))->assertCreated()->assertJsonPath('data.total', '11000.00');
        $this->shop('beta');
        $foreign = DeliveryZone::create(['name' => 'Other', 'city' => 'Test', 'fee' => '2000.00', 'status' => 'ACTIVE']);
        $this->getJson('/shops/alpha/checkout?delivery_zone_id='.$foreign->id)->assertUnprocessable();
    }

    public function test_validation_changed_cart_expired_and_foreign_intents_cannot_create_orders(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $token = $this->token();
        $this->postJson('/shops/alpha/checkout', $this->data(['customer_phone' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        $this->postJson('/shops/alpha/checkout', $this->data(['customer_email' => str_repeat('a', 145).'@example.test']))->assertUnprocessable();
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token.'x']))->assertStatus(419);
        $this->cart($variant, 1);
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token]))->assertStatus(409);
        $token = $this->token();
        $this->travel(2)->hours();
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token]))->assertStatus(419);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_missing_schema_and_disabled_services_fail_closed(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $token = $this->token();
        StoreSetting::first()->update(['pickup_enabled' => false, 'delivery_enabled' => false]);
        $this->get('/boutiques/alpha/commande')->assertOk()->assertDontSee('name="checkout_token"', false);
        $this->post('/boutiques/alpha/commande', $this->data(['checkout_token' => $token]))->assertUnprocessable();
        Schema::table('orders', fn ($table) => $table->dropUnique('orders_checkout_key_unique'));
        $this->postJson('/shops/alpha/checkout', $this->data())->assertStatus(503);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_bilingual_empty_pages_and_suspended_store(): void
    {
        $this->shop();
        foreach (['fr', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/boutiques/alpha/commande')->assertOk()
                ->assertSee(__('checkout.title', [], $locale))->assertDontSee('name="checkout_token"', false);
        }
        Store::where('slug', 'alpha')->update(['status' => 'SUSPENDED']);
        $this->get('/boutiques/alpha/commande')->assertNotFound();
        $this->postJson('/shops/alpha/checkout', $this->data())->assertNotFound();
    }

    public function test_unique_sql_failure_rolls_back_all_order_and_reservation_writes(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $this->token();
        $this->postJson('/shops/alpha/checkout', $this->data())->assertCreated();
        $original = Order::firstOrFail();
        try {
            DB::transaction(function () use ($variant, $original) {
                $duplicate = app(CreateOrder::class)->execute(
                    customerName: 'Test', customerPhone: '690000001', customerEmail: null,
                    fulfillmentType: 'PICKUP', items: [['product_variant_id' => $variant->id, 'quantity' => 2]]);
                $duplicate->forceFill(['checkout_key_hash' => $original->checkout_key_hash])->save();
            });
            $this->fail('Expected the SQL unique constraint.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 1);
            $this->assertDatabaseCount('stock_reservations', 1);
            $this->assertSame(2, $variant->stockLevel->fresh()->reserved_quantity);
            $this->assertSame(10, $variant->stockLevel->fresh()->physical_quantity);
        }
    }

    public function test_guest_intent_cannot_transfer_to_buyer_or_other_store(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $token = $this->token();
        $this->shop('beta');
        $this->postJson('/shops/beta/checkout', $this->data(['checkout_token' => $token]))->assertStatus(419);
        $buyer = User::factory()->create(['is_buyer' => true]);
        $this->actingAs($buyer, 'buyer');
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token]))->assertStatus(419);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_checkout_posts_enforce_csrf(): void
    {
        $this->shop();
        $this->app->instance(PreventRequestForgery::class,
            new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
        $this->post('/boutiques/alpha/commande', $this->data())->assertStatus(419);
        $this->postJson('/shops/alpha/checkout', $this->data())->assertStatus(419);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_matching_contacts_never_grant_access_to_a_historical_order(): void
    {
        $variant = $this->shop();
        $old = app(CreateOrder::class)->execute(
            customerName: 'Historical', customerPhone: '690000001', customerEmail: null,
            fulfillmentType: 'PICKUP', items: [['product_variant_id' => $variant->id, 'quantity' => 1]]);
        $buyer = User::factory()->create(['is_buyer' => true, 'phone' => '690000001']);
        $this->actingAs($buyer, 'buyer');
        $this->cart($variant);
        $this->token();
        $this->postJson('/shops/alpha/checkout', $this->data())->assertCreated();
        $this->assertNull($old->fresh()->buyer_user_id);
        $this->get('/boutiques/alpha/commande/'.$old->id.'/confirmation')->assertNotFound();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_guest_receipt_expires_and_foreign_store_has_no_receipt_access(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $this->token();
        $response = $this->postJson('/shops/alpha/checkout', $this->data())->assertCreated();
        $id = $response->json('data.order_id');
        $this->shop('beta');
        $this->get('/boutiques/beta/commande/'.$id.'/confirmation')->assertNotFound();
        $this->travel(3)->hours();
        $this->get($response->json('data.confirmation_url'))->assertNotFound();
    }

    public function test_changed_price_requires_a_new_explicit_confirmation_for_guest_and_buyer(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $token = $this->token();
        $variant->update(['promo_price' => '4000.00']);
        $review = $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token, 'total' => '1']))
            ->assertStatus(409)->assertJsonPath('data.requires_confirmation', true)->assertJsonPath('data.total.total', '8000.00');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
        $confirmed = $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $review->json('data.token')]))->assertCreated();
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token]))->assertCreated()
            ->assertJsonPath('data.order_id', $confirmed->json('data.order_id'));
        $order = Order::firstOrFail();
        $variant->update(['promo_ends_at' => now()->subSecond()]);
        $this->assertSame('8000.00', $order->fresh()->total);
        $this->assertSame('4000.00', $order->items->first()->final_unit_price);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, $variant->stockLevel->fresh()->reserved_quantity);

        $buyer = User::factory()->create(['is_buyer' => true]);
        $this->actingAs($buyer, 'buyer');
        $this->cart($variant, 1);
        $token = $this->token();
        $variant->update(['price' => '6000.00']);
        $review = $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token]))->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $review->json('data.token')]))->assertCreated()->assertJsonPath('data.total', '6000.00');
        $this->assertSame($buyer->id, Order::orderByDesc('id')->firstOrFail()->buyer_user_id);
    }

    public function test_html_expired_promotion_redirects_to_new_review_without_creating_an_order(): void
    {
        $variant = $this->shop();
        $variant->update(['promo_price' => '4000.00', 'promo_ends_at' => now()->addMinute()]);
        $this->cart($variant);
        $token = $this->token();
        $this->travel(2)->minutes();
        $response = $this->post('/boutiques/alpha/commande', $this->data(['checkout_token' => $token]));
        $response->assertRedirect(route('marketplace.shops.checkout', ['shopSlug' => 'alpha', 'fulfillment_type' => 'PICKUP']))->assertSessionHasErrors('checkout_token');
        $this->assertDatabaseCount('orders', 0);
        $page = $this->get($response->headers->get('Location'))->assertOk()->assertSee('10 000');
        preg_match('/name="checkout_token" value="([^"]+)"/', $page->getContent(), $matches);
        $this->post('/boutiques/alpha/commande', $this->data(['checkout_token' => html_entity_decode($matches[1])]))->assertStatus(303);
        $this->assertSame('10000.00', Order::firstOrFail()->total);
    }

    public function test_json_without_a_review_returns_a_quote_to_confirm_instead_of_silently_creating_an_order(): void
    {
        $variant = $this->shop();
        $this->cart($variant);
        $review = $this->postJson('/shops/alpha/checkout', $this->data())->assertStatus(409)->assertJsonPath('data.requires_confirmation', true);
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $review->json('data.token')]))->assertCreated();
    }

    public function test_delivery_fee_changes_require_reconfirmation_and_do_not_discount_the_fee(): void
    {
        $variant = $this->shop();
        $variant->update(['promo_price' => '4000.00']);
        $zone = DeliveryZone::create(['name' => 'Synthetic zone', 'city' => 'Douala', 'status' => 'ACTIVE', 'fee' => '1000.00']);
        $this->cart($variant);
        $token = $this->getJson('/shops/alpha/checkout?fulfillment_type=DELIVERY&delivery_zone_id='.$zone->id)->assertOk()->json('data.token');
        $zone->update(['fee' => '1500.00']);
        $data = $this->data(['checkout_token' => $token, 'fulfillment_type' => 'DELIVERY', 'delivery_zone_id' => $zone->id, 'delivery_address' => 'Fictional address']);
        $review = $this->postJson('/shops/alpha/checkout', $data)->assertStatus(409)->assertJsonPath('data.total.total', '9500.00');
        $this->assertDatabaseCount('orders', 0);
        $data['checkout_token'] = $review->json('data.token');
        $this->postJson('/shops/alpha/checkout', $data)->assertCreated()->assertJsonPath('data.delivery_fee', '1500.00')->assertJsonPath('data.discount_amount', '2000.00');
    }

    public function test_zero_total_order_is_refused_and_rolls_back_customer_and_reservations(): void
    {
        $variant = $this->shop();
        $variant->update(['promo_price' => '0.00']);
        $this->cart($variant);
        $token = $this->token();
        $this->postJson('/shops/alpha/checkout', $this->data(['checkout_token' => $token]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, $variant->stockLevel->fresh()->reserved_quantity);
    }
}
