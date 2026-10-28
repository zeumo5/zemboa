<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreateCashPayment;
use App\Actions\Pos\OpenPosSession;
use App\Actions\Product\CreateProduct;
use App\Actions\Returns\CreateReturnRequest;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PosRegister;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\SalesAccess;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProfessionalSalesTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Uncached SQLite :memory: only.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function fixture(string $slug = 'sales', bool $paid = true): array
    {
        $store = Store::create(['name' => 'Fictional '.$slug, 'slug' => $slug, 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        StoreSetting::create(['pickup_enabled' => true, 'returns_enabled' => true, 'return_window_days' => 7, 'allow_partial_payments' => true, 'minimum_deposit_percent' => 50]);
        $category = Category::create(['name' => 'Fictional category', 'slug' => 'category', 'status' => 'ACTIVE']);
        $product = app(CreateProduct::class)->execute(['name' => 'Fictional product '.$slug, 'slug' => 'product', 'category_id' => $category->id, 'status' => 'ACTIVE', 'default_variant' => ['sku' => 'SKU-'.$slug, 'price' => '1000']]);
        $variant = $product->variants()->firstOrFail();
        app(ReceiveStock::class)->execute($variant->id, 50);
        $actor = User::factory()->create(['name' => 'Fictional owner', 'store_id' => $store->id, 'status' => 'ACTIVE', 'is_buyer' => false, 'email_verified_at' => now(), 'preferred_locale' => 'fr']);
        $actor->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $this->actingAs($actor, 'web');
        $order = app(CreateOrder::class)->execute('Recorded customer '.$slug, '699000001', 'snapshot@example.test', 'PICKUP', [['product_variant_id' => $variant->id, 'quantity' => 2]]);
        if ($paid) {
            $payment = app(CreateCashPayment::class)->execute($order->id, '2000.00', '2000.00');
            app(ConfirmPayment::class)->execute($payment->id, $actor->id);
        }

        return compact('store', 'actor', 'order', 'variant', 'product');
    }

    private function prepare(array $f, string $step, ?string $version = null): TestResponse
    {
        return $this->post(route('professional.orders.prepare', [$f['order'], $step]), ['version' => $version ?? SalesAccess::preparationVersion($f['order']->fresh())]);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['payments', 'stock_levels', 'stock_reservations', 'stock_movements'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $result;
    }

    private function delivered(array $f): void
    {
        $f['order']->forceFill(['fulfillment_type' => 'DELIVERY'])->save();
        Delivery::create(['order_id' => $f['order']->id, 'status' => 'DELIVERED', 'delivered_at' => now()->subDay()]);
    }

    public function test_owner_preparation_is_audited_ordered_and_does_not_change_stock_or_payments(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $version = SalesAccess::preparationVersion($f['order']->fresh());
        $this->prepare($f, 'ready', $version)->assertConflict();
        $this->prepare($f, 'start', $version)->assertRedirect()->assertSessionHasNoErrors();
        $started = $f['order']->fresh();
        $this->assertSame($f['actor']->id, (int) $started->preparation_started_by);
        $this->assertNotNull($started->preparation_started_at);
        $this->prepare($f, 'start', $version)->assertConflict();
        $this->prepare($f, 'ready', $version)->assertConflict();
        $this->prepare($f, 'ready')->assertRedirect()->assertSessionHasNoErrors();
        $ready = $f['order']->fresh();
        $this->assertSame($f['actor']->id, (int) $ready->ready_by);
        $this->assertNotNull($ready->ready_at);
        $this->assertSame($started->preparation_started_at->toISOString(), $ready->preparation_started_at->toISOString());
        $this->prepare($f, 'ready')->assertConflict();
        $this->assertSame('PENDING', $ready->status);
        $this->assertSame('PAID', $ready->payment_status);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_manager_can_prepare_but_cannot_use_owner_after_sales_endpoints(): void
    {
        $f = $this->fixture();
        $f['actor']->roles()->sync([Role::where('code', 'STORE_MANAGER')->firstOrFail()->id]);
        $this->prepare($f, 'start')->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('professional.orders.show', $f['order']))->assertOk();
        $this->get(route('professional.customers.index'))->assertOk();
        $this->get(route('professional.returns.index'))->assertForbidden();
        $this->get(route('professional.returns.create', $f['order']))->assertForbidden();
        $this->post(route('professional.returns.store', $f['order']), [])->assertForbidden();
    }

    public function test_pages_render_and_preserve_json_contract(): void
    {
        $f = $this->fixture();
        $this->get(route('professional.orders.index'))->assertOk()->assertSee($f['order']->order_number);
        $this->get(route('professional.orders.show', $f['order']))->assertOk()->assertSee('SKU-sales');
        $this->get(route('professional.customers.index'))->assertOk()->assertSee('Recorded customer sales');
        $this->get(route('professional.customers.show', ['local', $f['order']->customer_id]))->assertOk();
        $this->get(route('professional.returns.index'))->assertOk();
        $this->getJson(route('orders.show', ['orderId' => $f['order']->id]))->assertOk()->assertJsonMissingPath('order.preparation_started_at');
    }

    public function test_forbidden_roles_inactive_users_and_suspended_store_cannot_access_sales(): void
    {
        $f = $this->fixture();
        foreach (['CASHIER', 'DELIVERY_AGENT', 'SUPER_ADMIN'] as $code) {
            $f['actor']->roles()->sync([Role::where('code', $code)->firstOrFail()->id]);
            $this->get(route('professional.orders.index'))->assertForbidden();
            $this->prepare($f, 'start')->assertForbidden();
        }
        $f['actor']->roles()->sync([Role::where('code', 'STORE_MANAGER')->firstOrFail()->id]);
        $f['actor']->forceFill(['email_verified_at' => null])->save();
        $this->get(route('professional.customers.index'))->assertForbidden();
        $f['actor']->forceFill(['email_verified_at' => now(), 'status' => 'INACTIVE'])->save();
        $this->get(route('professional.orders.index'))->assertForbidden();
        $f['actor']->forceFill(['status' => 'ACTIVE'])->save();
        $f['actor']->roles()->attach(Role::where('code', 'STORE_OWNER')->firstOrFail());
        $this->prepare($f, 'start')->assertForbidden();
        $f['actor']->roles()->sync([Role::where('code', 'STORE_OWNER')->firstOrFail()->id]);
        $f['store']->forceFill(['status' => 'SUSPENDED'])->save();
        $this->get(route('professional.orders.index'))->assertForbidden();
    }

    public function test_read_permission_does_not_grant_preparation_or_after_sales_mutations(): void
    {
        $f = $this->fixture();
        $role = $f['actor']->roles()->firstOrFail();
        $role->permissions()->detach(Permission::where('code', 'orders.update')->firstOrFail()->id);
        $this->get(route('professional.orders.index'))->assertOk();
        $this->prepare($f, 'start')->assertForbidden();
        $this->delivered($f);
        $role->permissions()->detach(Permission::where('code', 'returns.manage')->firstOrFail()->id);
        $this->get(route('professional.returns.index'))->assertOk();
        $this->post(route('professional.returns.store', $f['order']), [])->assertForbidden();
    }

    public function test_cross_store_orders_clients_and_return_cases_are_never_exposed(): void
    {
        $a = $this->fixture('alpha');
        $b = $this->fixture('beta');
        $this->delivered($b);
        $case = app(CreateReturnRequest::class)->execute($b['order']->id, [['order_item_id' => $b['order']->items()->firstOrFail()->id, 'quantity' => 1]], 'Fictional defect');
        $this->actingAs($a['actor'], 'web');
        app(TenantContext::class)->setFromStore($a['store']);
        $this->get(route('professional.orders.show', $b['order']))->assertNotFound();
        $this->prepare($b, 'start')->assertNotFound();
        $this->get(route('professional.customers.show', ['local', $b['order']->customer_id]))->assertNotFound();
        $this->get(route('professional.returns.show', $case))->assertNotFound();
        $this->post(route('professional.returns.update', [$case, 'receive']), [])->assertNotFound();
        foreach (['professional.orders.index', 'professional.customers.index', 'professional.returns.index'] as $name) {
            $this->get(route($name, ['q' => 'beta', 'store_id' => $b['store']->id]))->assertOk()->assertDontSee('Recorded customer beta');
        }
    }

    public function test_unpaid_cancelled_dispatched_and_inconsistent_orders_are_not_prepared(): void
    {
        $f = $this->fixture(paid: false);
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $payment = app(CreateCashPayment::class)->execute($f['order']->id, '1000.00', '1000.00');
        app(ConfirmPayment::class)->execute($payment->id, $f['actor']->id, now()->addDay()->toDateTimeString());
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $f['order']->forceFill(['payment_status' => 'PAID'])->save();
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $f['order']->forceFill(['status' => 'CANCELLED'])->save();
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $f['order']->forceFill(['status' => 'PENDING'])->save();
        $this->delivered($f);
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $this->assertNull($f['order']->fresh()->preparation_started_at);
    }

    public function test_missing_preparation_schema_preserves_reading_and_refuses_mutation(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['ready_by']);
        });
        $f = $this->fixture();
        $before = $this->snapshot();
        $this->get(route('professional.orders.index'))->assertOk();
        $this->get(route('professional.orders.show', $f['order']))->assertOk();
        $this->prepare($f, 'start')->assertStatus(503);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_customer_views_use_order_snapshots_without_reading_or_merging_global_profiles(): void
    {
        $f = $this->fixture();
        $buyer = User::factory()->create(['name' => 'PRIVATE-GLOBAL-NAME', 'email' => 'private-global@example.test', 'phone' => '+237699000099', 'is_buyer' => true, 'store_id' => null]);
        $f['order']->forceFill(['buyer_user_id' => $buyer->id])->save();
        $this->get(route('professional.customers.index', ['source' => 'buyer']))->assertOk()->assertSee('Recorded customer sales')->assertDontSee('PRIVATE-GLOBAL-NAME')->assertDontSee('private-global@example.test');
        $this->get(route('professional.customers.show', ['buyer', $buyer->id]))->assertOk()->assertSee('snapshot@example.test')->assertDontSee('PRIVATE-GLOBAL-NAME');
        $other = User::factory()->create(['store_id' => null, 'is_buyer' => true, 'phone' => '+237699000098']);
        $this->get(route('professional.customers.show', ['buyer', $other->id]))->assertNotFound();
        $f['order']->forceFill(['buyer_user_id' => null, 'customer_id' => null])->save();
        $this->get(route('professional.customers.index', ['source' => 'guest']))->assertOk()->assertSee('Recorded customer sales');
        $this->get(route('professional.customers.show', ['guest', $f['order']->id]))->assertOk();
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('PRIVATE-GLOBAL-NAME', $buyer->fresh()->name);
    }

    public function test_return_lifecycle_inspection_restock_and_refund_remain_controlled(): void
    {
        $f = $this->fixture();
        $this->delivered($f);
        $form = $this->get(route('professional.returns.create', $f['order']))->assertOk();
        $payload = ['version' => $form->viewData('version'), 'reason' => 'Fictional defect', 'items' => [$f['order']->items()->firstOrFail()->id => 1]];
        $this->post(route('professional.returns.store', $f['order']), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('professional.returns.store', $f['order']), $payload)->assertConflict();
        $case = ReturnRequest::firstOrFail();
        $this->get(route('professional.returns.show', $case))->assertOk();
        $this->post(route('professional.returns.update', [$case, 'review']), ['decision' => 'REJECTED'])->assertSessionHasErrors('review_notes');
        $this->post(route('professional.returns.update', [$case, 'review']), ['decision' => 'APPROVED'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('professional.returns.update', [$case, 'receive']), [])->assertRedirect()->assertSessionHasNoErrors();
        $item = $case->items()->firstOrFail();
        $this->post(route('professional.returns.update', [$case, 'inspect']), ['items' => [['return_request_item_id' => $item->id, 'restockable_quantity' => 2]]])->assertSessionHasErrors('restockable_quantity');
        $this->post(route('professional.returns.update', [$case, 'inspect']), ['items' => [['return_request_item_id' => $item->id, 'restockable_quantity' => 1, 'inspection_notes' => 'Checked physical condition']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('professional.returns.show', $case))->assertOk()->assertSee('Checked physical condition');
        $this->post(route('professional.returns.update', [$case, 'restock']), [])->assertSessionHasErrors('confirmed_physical');
        $this->post(route('professional.returns.update', [$case, 'restock']), ['confirmed_physical' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('professional.returns.update', [$case, 'restock']), ['confirmed_physical' => '1'])->assertSessionHasErrors('stock');
        $this->assertSame(49, $f['variant']->stockLevel->fresh()->physical_quantity);
        $this->assertSame(0, $f['variant']->stockLevel->fresh()->reserved_quantity);
        foreach (['prepare', 'approve', 'simulate'] as $operation) {
            $this->post(route('professional.returns.refund', [$case, $operation]), ['confirmed_simulation' => 1])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('SIMULATED', Refund::firstOrFail()->status);
        $this->assertSame('PAID', $f['order']->fresh()->payment_status);
        $this->assertSame(1, StockMovement::where('type', 'RETURN')->count());
    }

    public function test_preparation_excludes_pos_and_preserves_both_actors_with_competing_versions(): void
    {
        $f = $this->fixture();
        $register = PosRegister::create(['name' => 'Fictional register', 'code' => 'POS-PREP', 'status' => 'ACTIVE']);
        $session = app(OpenPosSession::class)->execute($register->id, '0.00', $f['actor']);
        $f['order']->forceFill(['pos_session_id' => $session->id])->save();
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $f['order']->forceFill(['pos_session_id' => null])->save();
        $version = SalesAccess::preparationVersion($f['order']->fresh());
        $this->prepare($f, 'start', $version)->assertRedirect()->assertSessionHasNoErrors();
        $manager = User::factory()->create(['store_id' => $f['store']->id, 'status' => 'ACTIVE', 'email_verified_at' => now(), 'is_buyer' => false]);
        $manager->roles()->attach(Role::where('code', 'STORE_MANAGER')->firstOrFail());
        $this->actingAs($manager, 'web');
        $this->prepare($f, 'ready', $version)->assertConflict();
        $this->prepare($f, 'ready')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($f['actor']->id, (int) $f['order']->fresh()->preparation_started_by);
        $this->assertSame($manager->id, (int) $f['order']->fresh()->ready_by);
        $this->assertArrayNotHasKey('ready_by', $f['order']->fresh()->toArray());
    }

    public function test_preparation_rolls_back_an_interrupted_update_without_touching_financial_history(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $interrupt = true;
        Order::updating(function ($order) use (&$interrupt): void {
            if ($interrupt && $order->isDirty('preparation_started_at')) {
                throw ValidationException::withMessages(['order' => 'Synthetic preparation failure']);
            }
        });
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $interrupt = false;
        $this->assertNull($f['order']->fresh()->preparation_started_at);
        $this->assertNull($f['order']->fresh()->preparation_started_by);
        $this->assertSame($before, $this->snapshot());
        $this->prepare($f, 'start')->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_paid_flag_with_unconverted_reservations_is_refused(): void
    {
        $f = $this->fixture();
        DB::table('stock_reservations')->where('reference_id', $f['order']->id)->update(['status' => 'ACTIVE']);
        $before = $this->snapshot();
        $this->prepare($f, 'start')->assertSessionHasErrors('order');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_order_filters_pagination_and_buyer_only_authentication(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 22; $i++) {
            $copy = $f['order']->fresh()->replicate();
            $copy->order_number = 'PAGINATED-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $copy->save();
        }
        $response = $this->get(route('professional.orders.index', ['q' => 'PAGINATED', 'payment' => 'PAID', 'mode' => 'PICKUP']))->assertOk();
        $this->assertSame(22, $response->viewData('orders')->total());
        $this->assertCount(20, $response->viewData('orders'));
        $this->get(route('professional.orders.index', ['from' => '2026-12-01', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
        auth('web')->logout();
        $buyer = User::factory()->create(['store_id' => null, 'is_buyer' => true, 'phone' => '+237699000097']);
        $this->actingAs($buyer, 'buyer');
        Auth::shouldUse('web');
        $this->assertFalse(auth('web')->check());
        $this->get(route('professional.orders.index'))->assertUnauthorized();
    }

    public function test_new_mutations_enforce_csrf(): void
    {
        $f = $this->fixture();
        $this->app->instance(PreventRequestForgery::class,
            new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
        $this->prepare($f, 'start')->assertStatus(419);
        $this->post(route('professional.returns.store', $f['order']), [])->assertStatus(419);
        $this->assertNull($f['order']->fresh()->preparation_started_at);
    }

    public function test_bilingual_http_renders_and_isolated_visual_fixtures(): void
    {
        $f = $this->fixture();
        $eligibleOrderHtml = [];
        foreach (['fr', 'en'] as $locale) {
            $f['actor']->forceFill(['preferred_locale' => $locale])->save();
            $eligibleOrderHtml[$locale] = $this->get(route('professional.orders.show', $f['order']))->assertOk()->getContent();
        }
        $this->delivered($f);
        $case = app(CreateReturnRequest::class)->execute($f['order']->id, [['order_item_id' => $f['order']->items()->firstOrFail()->id, 'quantity' => 1]], 'Fictional after-sales request');
        foreach (['fr', 'en'] as $locale) {
            $f['actor']->forceFill(['preferred_locale' => $locale])->save();
            $urls = ['orders' => route('professional.orders.index'), 'order' => route('professional.orders.show', $f['order']), 'customers' => route('professional.customers.index'), 'customer' => route('professional.customers.show', ['local', $f['order']->customer_id]), 'buyers' => route('professional.customers.index', ['source' => 'buyer']), 'guests' => route('professional.customers.index', ['source' => 'guest']), 'returns' => route('professional.returns.index'), 'return' => route('professional.returns.show', $case), 'return-create' => route('professional.returns.create', $f['order'])];
            foreach ($urls as $kind => $url) {
                $response = $this->get($url)->assertOk()->assertSee('lang="'.$locale.'"', false);
                if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                    $directory = storage_path('demo/phase20c2c-preview');
                    if (! is_dir($directory)) {
                        mkdir($directory, 0700, true);
                    }
                    $html = str_replace(csrf_token(), 'REDACTED-CSRF', $kind === 'order' ? $eligibleOrderHtml[$locale] : $response->getContent());
                    $html = preg_replace('/name="version" value="[^"]*"/', 'name="version" value="REDACTED-VERSION"', $html);
                    file_put_contents($directory.'/'.$kind.'-'.$locale.'.html', $html);
                }
            }
        }
    }
}
