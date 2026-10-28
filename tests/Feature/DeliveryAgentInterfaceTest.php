<?php

namespace Tests\Feature;

use App\Actions\Deliveries\AssignDeliveryAgent;
use App\Actions\Deliveries\UpdateDeliveryStatus;
use App\Actions\Orders\CreateOrder;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreateCashPayment;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\DeliveryZone;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class DeliveryAgentInterfaceTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    private const BASE = '/espace-livreur';

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Only uncached SQLite :memory: allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function actor(Store $store, string $role = 'DELIVERY_AGENT'): User
    {
        $actor = User::factory()->create(['name' => 'Fictional courier', 'email' => fake()->unique()->numerify('courier-########').'@example.test', 'store_id' => $store->id, 'status' => 'ACTIVE', 'email_verified_at' => now(), 'password' => 'Fictional-password-123', 'is_buyer' => false]);
        $actor->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $actor;
    }

    private function fixture(bool $paid = true): array
    {
        $store = Store::create(['name' => 'Fictional delivery store', 'slug' => 'delivery-'.fake()->unique()->numerify('########'), 'status' => 'ACTIVE', 'address' => 'Fictional store address, Douala']);
        $owner = $this->actor($store, 'STORE_OWNER');
        $agent = $this->actor($store);
        app(TenantContext::class)->setFromStore($store);
        StoreSetting::create(['delivery_enabled' => true, 'pickup_enabled' => true]);
        $this->actingAs($owner, 'web');
        $category = Category::create(['name' => 'Fictional category', 'slug' => 'parcels', 'status' => 'ACTIVE']);
        $product = Product::create(['name' => 'Fictional parcel', 'slug' => 'parcel', 'category_id' => $category->id, 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'PARCEL-'.fake()->unique()->numerify('########'), 'price' => '2500.00', 'status' => 'ACTIVE']);
        $stock = StockLevel::firstOrCreate(['product_variant_id' => $variant->id]);
        $stock->forceFill(['physical_quantity' => 10, 'reserved_quantity' => 0])->save();
        $zone = DeliveryZone::create(['name' => 'Fictional area', 'city' => 'Douala', 'fee' => '1000', 'status' => 'ACTIVE']);
        $order = app(CreateOrder::class)->execute('Fictional recipient', '690000001', 'recipient@example.test', 'DELIVERY', [['product_variant_id' => $variant->id, 'quantity' => 2]], $zone->id, 'Fictional delivery address', 'Fictional area', 'Use the fictional main entrance');
        if ($paid) {
            $payment = app(CreateCashPayment::class)->execute($order->id, (string) $order->total, (string) $order->total);
            app(ConfirmPayment::class)->execute($payment->id, $owner->id);
        }
        $delivery = app(AssignDeliveryAgent::class)->execute($order->id, $agent->id);
        $this->actingAs($agent, 'web');
        app(TenantContext::class)->setFromUser($agent);

        return compact('store', 'owner', 'agent', 'order', 'delivery', 'stock', 'variant');
    }

    private function transition(Delivery $delivery, string $status, array $extra = []): TestResponse
    {
        return $this->post(self::BASE.'/missions/'.$delivery->id.'/statut', array_merge(['status' => $status, 'confirmed' => '1'], $extra));
    }

    public function test_professional_login_redirects_courier_and_logout_invalidates_access(): void
    {
        $f = $this->fixture();
        auth('web')->logout();
        $this->post('/login', ['email' => $f['agent']->email, 'password' => 'Fictional-password-123'])->assertRedirect(route('courier.index'));
        $this->get(self::BASE)->assertOk()->assertSee($f['order']->order_number);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->get(self::BASE)->assertUnauthorized();
    }

    public function test_other_roles_and_buyer_guard_cannot_access_courier_space(): void
    {
        $f = $this->fixture();
        foreach (['STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'SUPER_ADMIN'] as $role) {
            $this->actingAs($this->actor($f['store'], $role), 'web');
            $this->get(self::BASE)->assertForbidden();
            $this->get(self::BASE.'/missions/'.$f['delivery']->id)->assertForbidden();
            $this->transition($f['delivery'], 'PICKED_UP')->assertForbidden();
        }
        auth('web')->logout();
        $buyer = User::factory()->create(['status' => 'ACTIVE', 'is_buyer' => true, 'store_id' => null]);
        $this->actingAs($buyer, 'buyer');
        $this->get(self::BASE)->assertForbidden();
    }

    public function test_unknown_additional_role_blocks_login_html_and_json(): void
    {
        $f = $this->fixture();
        $f['agent']->roles()->attach(Role::create(['code' => 'FUTURE_ROLE', 'name' => 'Future role']));
        $this->get(self::BASE)->assertForbidden();
        $this->getJson(route('deliveries.index'))->assertForbidden();
        $this->patchJson(route('deliveries.update-status', $f['delivery']->id), ['status' => 'PICKED_UP'])->assertForbidden();
        auth('web')->logout();
        $this->post('/login', ['email' => $f['agent']->email, 'password' => 'Fictional-password-123'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_disabled_unverified_and_suspended_accounts_fail_closed(): void
    {
        $f = $this->fixture();
        $f['agent']->forceFill(['status' => 'DISABLED'])->save();
        $this->get(self::BASE)->assertForbidden();
        $this->patchJson(route('deliveries.update-status', $f['delivery']->id), ['status' => 'PICKED_UP'])->assertForbidden();
        $f['agent']->forceFill(['status' => 'ACTIVE', 'email_verified_at' => null])->save();
        $this->get(self::BASE)->assertForbidden();
        $f['agent']->forceFill(['email_verified_at' => now()])->save();
        $f['store']->update(['status' => 'SUSPENDED']);
        $this->get(self::BASE)->assertForbidden();
        $this->transition($f['delivery'], 'PICKED_UP')->assertForbidden();
    }

    public function test_missions_and_recipient_data_are_scoped_to_assignee_and_store(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->actor($f['store']), 'web');
        $this->get(self::BASE)->assertOk()->assertDontSee($f['order']->order_number)->assertSee(__('courier.empty'));
        $this->get(self::BASE.'/missions/'.$f['delivery']->id)->assertNotFound();
        $this->transition($f['delivery'], 'PICKED_UP')->assertNotFound();
        $this->getJson(route('deliveries.show', $f['delivery']->id))->assertNotFound();
        $foreign = $this->fixture();
        $this->get(self::BASE.'/missions/'.$f['delivery']->id)->assertNotFound();
        $this->transition($f['delivery'], 'PICKED_UP')->assertNotFound();
        $this->get(self::BASE)->assertOk()->assertSee($foreign['order']->order_number)->assertDontSee($f['order']->order_number);
    }

    public function test_detail_minimises_personal_and_financial_data_and_blocks_cache(): void
    {
        $f = $this->fixture();
        $page = $this->get(self::BASE.'/missions/'.$f['delivery']->id)->assertOk()->assertSee('Fictional recipient')->assertSee('Fictional parcel')->assertSee('Fictional delivery address')->assertDontSee('recipient@example.test')->assertDontSee('cash_received')->assertDontSee('6 000 FCFA')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('<iframe', $page->getContent());
    }

    public function test_paid_handover_records_steps_without_payment_or_stock_mutation(): void
    {
        $f = $this->fixture();
        $stockBefore = $f['stock']->fresh()->getAttributes();
        $paymentsBefore = Payment::get()->toArray();
        $this->transition($f['delivery'], 'DELIVERED')->assertSessionHasErrors('status');
        $this->transition($f['delivery'], 'PICKED_UP')->assertRedirect(route('courier.show', $f['delivery']->id));
        $this->transition($f['delivery'], 'PICKED_UP')->assertSessionHasErrors('status');
        $this->assertNotNull($f['delivery']->fresh()->picked_up_at);
        $this->transition($f['delivery'], 'DELIVERED')->assertRedirect();
        $this->assertSame('DELIVERED', $f['delivery']->fresh()->status);
        $this->assertNotNull($f['delivery']->fresh()->delivered_at);
        $this->transition($f['delivery'], 'DELIVERED')->assertSessionHasErrors('status');
        $this->assertSame($paymentsBefore, Payment::get()->toArray());
        $this->assertSame($stockBefore, $f['stock']->fresh()->getAttributes());
        $this->assertSame('PENDING', $f['order']->fresh()->status);
    }

    public function test_unpaid_order_cannot_be_handed_over_and_courier_cannot_collect_payment(): void
    {
        $f = $this->fixture(false);
        $this->transition($f['delivery'], 'PICKED_UP')->assertRedirect();
        $this->get(self::BASE.'/missions/'.$f['delivery']->id)->assertOk()->assertSee(__('courier.payment_wait'))->assertDontSee('value="DELIVERED"', false);
        $this->transition($f['delivery'], 'DELIVERED')->assertSessionHasErrors('payment_status');
        $this->assertSame('PICKED_UP', $f['delivery']->fresh()->status);
        $this->assertNull($f['delivery']->fresh()->delivered_at);
        $this->assertSame(0, Payment::count());
        try {
            app(CreateCashPayment::class)->execute($f['order']->id, '6000', '6000');
            $this->fail('Courier cannot collect payment.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame('UNPAID', $f['order']->fresh()->payment_status);
        $this->assertSame(10, (int) $f['stock']->fresh()->physical_quantity);
    }

    public function test_mutation_requires_confirmation_and_rejects_sensitive_fields(): void
    {
        $f = $this->fixture();
        $this->post(self::BASE.'/missions/'.$f['delivery']->id.'/statut', ['status' => 'PICKED_UP'])->assertSessionHasErrors('confirmed');
        foreach (['total' => '1', 'delivery_fee' => '0', 'payment_status' => 'PAID', 'assigned_to' => $f['owner']->id, 'store_id' => 999] as $key => $value) {
            $this->transition($f['delivery'], 'PICKED_UP', [$key => $value])->assertSessionHasErrors($key);
        }
        $this->assertSame('ASSIGNED', $f['delivery']->fresh()->status);
    }

    public function test_pickup_orders_are_never_courier_assignments_even_if_a_bad_row_exists(): void
    {
        $f = $this->fixture();
        $f['order']->update(['fulfillment_type' => 'PICKUP']);
        $this->get(self::BASE)->assertOk()->assertDontSee($f['order']->order_number);
        $this->get(self::BASE.'/missions/'.$f['delivery']->id)->assertNotFound();
        $this->transition($f['delivery'], 'PICKED_UP')->assertNotFound();
        $this->patchJson(route('deliveries.update-status', $f['delivery']->id), ['status' => 'PICKED_UP'])->assertUnprocessable();
    }

    public function test_failure_is_terminal_and_preserves_reserved_stock(): void
    {
        $f = $this->fixture(false);
        $this->transition($f['delivery'], 'FAILED')->assertSessionHasErrors('failure_reason');
        $this->transition($f['delivery'], 'PICKED_UP')->assertRedirect();
        $before = $f['stock']->fresh()->getAttributes();
        $this->transition($f['delivery'], 'FAILED', ['failure_reason' => 'Fictional recipient unavailable'])->assertRedirect();
        $this->assertSame('FAILED', $f['delivery']->fresh()->status);
        $this->assertNotNull($f['delivery']->fresh()->failed_at);
        $this->transition($f['delivery'], 'PICKED_UP')->assertSessionHasErrors('status');
        $this->assertSame($before, $f['stock']->fresh()->getAttributes());
        $this->assertNull($f['delivery']->fresh()->returned_at);
    }

    public function test_failed_write_rolls_back_without_partial_transition(): void
    {
        $f = $this->fixture();
        $dispatcher = Delivery::getEventDispatcher();
        Delivery::setEventDispatcher(clone $dispatcher);
        Delivery::saved(function (Delivery $delivery): void {
            if ($delivery->status === 'PICKED_UP') {
                throw new RuntimeException('Simulated in-memory write failure.');
            }
        });
        try {
            app(UpdateDeliveryStatus::class)->execute($f['delivery']->id, 'PICKED_UP');
            $this->fail('Expected write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated in-memory write failure.', $exception->getMessage());
        } finally {
            Delivery::setEventDispatcher($dispatcher);
        }
        $this->assertSame('ASSIGNED', $f['delivery']->fresh()->status);
        $this->assertNull($f['delivery']->fresh()->picked_up_at);
    }

    public function test_expired_or_missing_reservation_prevents_collection(): void
    {
        $f = $this->fixture(false);
        $f['order']->update(['reservation_expires_at' => now()->subMinute()]);
        $this->transition($f['delivery'], 'PICKED_UP')->assertSessionHasErrors('order');
        $f['order']->update(['reservation_expires_at' => now()->addHour()]);
        StockReservation::where('reference_id', $f['order']->id)->update(['status' => 'RELEASED']);
        $this->transition($f['delivery'], 'PICKED_UP')->assertSessionHasErrors('order');
        $this->assertSame('ASSIGNED', $f['delivery']->fresh()->status);
    }

    public function test_delivery_requires_real_fee_and_address(): void
    {
        $f = $this->fixture();
        $f['order']->update(['delivery_fee' => '0']);
        $this->transition($f['delivery'], 'PICKED_UP')->assertSessionHasErrors('order');
        $this->actingAs($f['owner'], 'web');
        $otherOrder = $f['order']->replicate();
        $otherOrder->order_number = 'FICT-NO-FEE';
        $otherOrder->save();
        $this->postJson(route('deliveries.assign', $otherOrder->id), ['assigned_to' => $f['agent']->id])->assertUnprocessable();
        $otherOrder->update(['delivery_fee' => '1000', 'delivery_address' => '']);
        $this->postJson(route('deliveries.assign', $otherOrder->id), ['assigned_to' => $f['agent']->id])->assertUnprocessable();
    }

    public function test_filters_counts_and_history_are_real_and_scoped(): void
    {
        $f = $this->fixture();
        $this->get(self::BASE.'?q=not-found')->assertOk()->assertSee(__('courier.empty'));
        $this->get(self::BASE.'?status=FAILED')->assertOk()->assertDontSee($f['order']->order_number);
        $this->get(self::BASE.'/historique')->assertOk()->assertDontSee($f['order']->order_number);
        $this->transition($f['delivery'], 'PICKED_UP')->assertRedirect();
        $this->transition($f['delivery'], 'DELIVERED')->assertRedirect();
        $this->get(self::BASE.'/historique?status=DELIVERED')->assertOk()->assertSee($f['order']->order_number);
        $this->get(self::BASE.'?status=UNKNOWN')->assertSessionHasErrors('status');
        $this->get(self::BASE)->assertViewHas('counts', fn ($counts) => (int) $counts['DELIVERED'] === 1);
    }

    public function test_owner_manager_permissions_remain_and_courier_cannot_self_assign(): void
    {
        $f = $this->fixture();
        $this->postJson(route('deliveries.assign', $f['order']->id), ['assigned_to' => $f['agent']->id])->assertForbidden();
        foreach ([$f['owner'], $this->actor($f['store'], 'STORE_MANAGER')] as $actor) {
            $this->actingAs($actor, 'web');
            $this->getJson(route('deliveries.show', $f['delivery']->id))->assertOk();
            $this->patchJson(route('deliveries.update-status', $f['delivery']->id), ['status' => 'PICKED_UP'])->assertForbidden();
        }
    }

    public function test_missing_permission_blocks_mutation(): void
    {
        $f = $this->fixture();
        Role::where('code', 'DELIVERY_AGENT')->firstOrFail()->permissions()->detach(Permission::where('code', 'deliveries.update')->value('id'));
        $this->get(self::BASE)->assertOk();
        $this->transition($f['delivery'], 'PICKED_UP')->assertForbidden();
    }

    public function test_html_and_json_delivery_transitions_keep_csrf_protection(): void
    {
        $f = $this->fixture();
        $this->app->instance(PreventRequestForgery::class,
            new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        $this->transition($f['delivery'], 'PICKED_UP')->assertStatus(419);
        $this->patchJson(route('deliveries.update-status', $f['delivery']->id), ['status' => 'PICKED_UP'])->assertStatus(419);
        $this->assertSame('ASSIGNED', $f['delivery']->fresh()->status);
    }

    public function test_courier_cannot_confirm_a_payment_created_by_the_merchant(): void
    {
        $f = $this->fixture(false);
        $this->actingAs($f['owner'], 'web');
        $payment = app(CreateCashPayment::class)->execute($f['order']->id, (string) $f['order']->total, (string) $f['order']->total);
        $this->actingAs($f['agent'], 'web');
        try {
            app(ConfirmPayment::class)->execute($payment->id, $f['agent']->id);
            $this->fail('Courier payment confirmation must be forbidden.');
        } catch (AuthorizationException) {
            $this->assertSame('PENDING', $payment->fresh()->status);
            $this->assertSame('UNPAID', $f['order']->fresh()->payment_status);
            $this->assertSame(10, (int) $f['stock']->fresh()->physical_quantity);
        }
    }

    public function test_assignment_rejects_a_courier_with_an_additional_role_and_return_is_owner_only(): void
    {
        $f = $this->fixture();
        $f['agent']->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $this->patchJson(route('deliveries.confirm-return', $f['delivery']->id))->assertForbidden();
        $this->actingAs($f['owner'], 'web');
        $copy = $f['order']->replicate();
        $copy->order_number = 'FICT-ROLE-REJECT';
        $copy->save();
        $this->postJson(route('deliveries.assign', $copy->id), ['assigned_to' => $f['agent']->id])->assertUnprocessable();
    }

    public function test_pagination_does_not_expose_unassigned_or_pickup_orders(): void
    {
        $f = $this->fixture();
        for ($index = 0; $index < 13; $index++) {
            $copy = $f['order']->replicate();
            $copy->order_number = 'FICT-PAGE-'.$index;
            $copy->save();
            Delivery::create(['order_id' => $copy->id, 'assigned_to' => $f['agent']->id, 'status' => 'ASSIGNED', 'assigned_at' => now()]);
        }
        $this->get(self::BASE)->assertOk()->assertViewHas('deliveries', fn ($rows) => $rows->total() === 14 && $rows->count() === 12);
        $this->get(self::BASE.'?page=2')->assertOk()->assertViewHas('deliveries', fn ($rows) => $rows->count() === 2);
    }

    public function test_bilingual_pages_are_rendered_from_memory_for_chrome(): void
    {
        $f = $this->fixture();
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            foreach (['index' => '', 'show' => '/missions/'.$f['delivery']->id, 'history' => '/historique'] as $kind => $path) {
                $response = $this->get(self::BASE.$path)->assertOk()->assertSee(__('courier.space'));
                if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                    $directory = storage_path('demo/phase19c-preview');
                    if (! is_dir($directory)) {
                        mkdir($directory, 0700, true);
                    }
                    file_put_contents($directory.'/'.$kind.'-'.$locale.'.html', $response->getContent());
                }
            }
        }
    }
}
