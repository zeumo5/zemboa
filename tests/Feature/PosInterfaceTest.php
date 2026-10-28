<?php

namespace Tests\Feature;

use App\Actions\Pos\OpenPosSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreDocumentSetting;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\PosCart;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PosInterfaceTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    private const BASE = '/espace-professionnel/caisse';

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
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function fixture(string $role = 'CASHIER', ?Store $store = null): array
    {
        $store ??= Store::create(['name' => 'Fictional POS store', 'slug' => 'pos-'.fake()->unique()->numerify('########'), 'status' => 'ACTIVE', 'phone' => '+237690000001', 'address' => 'Fictional local address']);
        $actor = User::factory()->create(['name' => 'Fictional cashier', 'email' => fake()->unique()->numerify('cashier-########').'@example.test', 'store_id' => $store->id, 'status' => 'ACTIVE', 'is_buyer' => false, 'email_verified_at' => now(), 'password' => 'Fictional-password-123']);
        $actor->roles()->attach(Role::where('code', $role)->firstOrFail());
        app(TenantContext::class)->setFromStore($store);
        $settings = StoreSetting::firstOrCreate([], ['pickup_enabled' => true]);
        $settings->forceFill(['commercial_configured' => true])->save();
        $this->actingAs($actor, 'web');
        $register = PosRegister::create(['name' => 'Fictional register', 'code' => 'REG-'.fake()->unique()->numerify('####'), 'status' => 'ACTIVE']);
        $session = in_array($role, ['CASHIER', 'STORE_OWNER', 'STORE_MANAGER'], true) ? app(OpenPosSession::class)->execute($register->id, '1000.00', $actor) : null;
        $category = Category::create(['name' => 'Fictional category', 'slug' => 'cat-'.fake()->unique()->numerify('####'), 'status' => 'ACTIVE']);
        $product = Product::create(['name' => 'Fictional keyboard', 'slug' => 'keyboard-'.fake()->unique()->numerify('####'), 'category_id' => $category->id, 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.fake()->unique()->numerify('########'), 'price' => '2500.00', 'status' => 'ACTIVE']);
        $stock = StockLevel::firstOrCreate(['product_variant_id' => $variant->id]);
        $stock->forceFill(['physical_quantity' => 10, 'reserved_quantity' => 0, 'low_stock_threshold' => 2])->save();

        return compact('store', 'actor', 'register', 'session', 'category', 'product', 'variant', 'stock');
    }

    private function saleData(array $fixture, string $mode = 'CASH'): array
    {
        $this->get(self::BASE)->assertOk();
        $this->postJson(self::BASE.'/panier', ['operation' => 'add', 'variant_id' => $fixture['variant']->id])->assertOk();
        $draft = session(app(PosCart::class)->key($fixture['actor']));
        $quote = app(PosCart::class)->quote($draft['items'], $fixture['actor']);

        return ['session_id' => $fixture['session']->id, 'key' => $draft['key'], 'quote_hash' => $quote['quote_hash'],
            'mode' => $mode, 'cash_received' => '5000', 'customer_name' => '', 'customer_phone' => '', 'customer_email' => ''];
    }

    public function test_cashier_login_reaches_real_pos_interface(): void
    {
        $f = $this->fixture();
        auth('web')->logout();
        $this->post('/login', ['email' => $f['actor']->email, 'password' => 'Fictional-password-123'])->assertRedirect(route('pos.interface.index'));
        $this->get(self::BASE)->assertOk()->assertSee($f['product']->name)->assertSee('2 500 FCFA');
    }

    public function test_native_form_quantities_are_normalized_and_pos_cart_is_separate_from_buyer_cart(): void
    {
        $f = $this->fixture();
        $this->saleData($f);
        session(['zemboa.cart' => ['store_id' => 123, 'items' => ['unchanged']]]);
        $this->post(self::BASE.'/panier', ['operation' => 'set', 'variant_id' => (string) $f['variant']->id, 'quantity' => '2'], ['Accept' => 'application/json'])->assertOk()->assertSee('5 000');
        $this->assertSame(2, session(app(PosCart::class)->key($f['actor']))['items'][$f['variant']->id]);
        $this->assertSame(['store_id' => 123, 'items' => ['unchanged']], session('zemboa.cart'));
        $this->assertSame(0, (int) $f['stock']->fresh()->reserved_quantity);
    }

    public function test_anonymous_cash_sale_uses_existing_actions_without_customer_and_decrements_once(): void
    {
        $f = $this->fixture();
        $data = $this->saleData($f);
        $this->assertSame(0, (int) $f['stock']->fresh()->reserved_quantity);
        $this->postJson(self::BASE.'/ventes', $data)->assertCreated();
        $order = Order::firstOrFail();
        $this->assertNull($order->customer_id);
        $this->assertNull($order->customer_phone);
        $this->assertSame(0, Customer::count());
        $this->assertSame('PAID', $order->payment_status);
        $this->assertSame('CONFIRMED', Payment::first()->status);
        $this->assertSame('2500.00', Payment::first()->change_given);
        $this->assertSame(9, (int) $f['stock']->fresh()->physical_quantity);
        $this->postJson(self::BASE.'/ventes', $data)->assertCreated();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(9, (int) $f['stock']->fresh()->physical_quantity);
        $this->assertTrue(Schema::hasIndex('orders', 'orders_pos_submission_unique', 'unique'));
    }

    public function test_unpaid_sale_reserves_without_physical_decrement_and_never_claims_paid(): void
    {
        $f = $this->fixture();
        $this->postJson(self::BASE.'/ventes', $this->saleData($f, 'UNPAID'))->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame(10, (int) $f['stock']->fresh()->physical_quantity);
        $this->assertSame(1, (int) $f['stock']->fresh()->reserved_quantity);
        $this->assertSame(0, Payment::count());
        $this->get(self::BASE.'/ventes/'.$order->id.'/document?format=58')->assertOk()->assertSee(__('pos_interface.document_unpaid'));
    }

    public function test_unpaid_sale_can_be_collected_later_without_double_decrement(): void
    {
        $f = $this->fixture();
        $this->postJson(self::BASE.'/ventes', $this->saleData($f, 'UNPAID'))->assertCreated();
        $order = Order::firstOrFail();
        $data = ['cash_received' => '5000', 'confirmed' => '1'];
        $url = self::BASE.'/ventes/'.$order->id.'/encaisser';
        $this->post($url, $data)->assertRedirect(route('pos.interface.show', $order->id));
        $this->post($url, $data)->assertRedirect(route('pos.interface.show', $order->id));
        $this->assertSame('PAID', $order->fresh()->payment_status);
        $this->assertSame(1, Payment::count());
        $this->assertSame(9, (int) $f['stock']->fresh()->physical_quantity);
        $this->assertSame(0, (int) $f['stock']->fresh()->reserved_quantity);
    }

    public function test_optional_pos_contact_fields_are_recorded_without_fabricated_customer(): void
    {
        $f = $this->fixture();
        $data = $this->saleData($f);
        $data['customer_name'] = 'Provided name only';
        $this->postJson(self::BASE.'/ventes', $data)->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame('Provided name only', $order->customer_name);
        $this->assertNull($order->customer_phone);
        $this->assertNull($order->customer_id);
        $this->assertSame(0, Customer::count());
        $this->get(self::BASE.'/ventes/'.$order->id.'/document?format=A4')->assertOk()->assertSee('Provided name only');
    }

    public function test_stale_competing_quotes_cannot_sell_the_last_unit_twice(): void
    {
        $a = $this->fixture();
        $a['stock']->forceFill(['physical_quantity' => 1])->save();
        $first = $this->saleData($a);
        $b = $this->fixture('CASHIER', $a['store']);
        $b['variant'] = $a['variant'];
        $second = $this->saleData($b);
        $this->actingAs($a['actor'], 'web')->postJson(self::BASE.'/ventes', $first)->assertCreated();
        $this->actingAs($b['actor'], 'web')->postJson(self::BASE.'/ventes', $second)->assertUnprocessable();
        $this->assertSame(1, Order::count());
        $this->assertSame(0, (int) $a['stock']->fresh()->physical_quantity);
    }

    public function test_phone_only_contact_remains_a_snapshot_without_fabricated_name(): void
    {
        $f = $this->fixture();
        $data = $this->saleData($f);
        $data['customer_phone'] = '690 123 456';
        $this->postJson(self::BASE.'/ventes', $data)->assertCreated();
        $order = Order::firstOrFail();
        $this->assertNull($order->customer_name);
        $this->assertNull($order->customer_id);
        $this->assertSame('+237690123456', $order->customer_phone);
        $this->assertSame(0, Customer::count());
    }

    public function test_missing_idempotency_index_blocks_sale_before_any_order(): void
    {
        $f = $this->fixture();
        $data = $this->saleData($f);
        Schema::table('orders', fn ($table) => $table->dropUnique('orders_pos_submission_unique'));
        $this->postJson(self::BASE.'/ventes', $data)->assertStatus(503);
        $this->assertSame(0, Order::count());
    }

    public function test_prices_promotions_are_server_calculated_and_browser_amounts_rejected(): void
    {
        $f = $this->fixture();
        $f['variant']->forceFill(['promo_price' => '1500.00'])->save();
        $data = $this->saleData($f);
        $this->postJson(self::BASE.'/ventes', $data + ['total' => '1'])->assertUnprocessable();
        $this->postJson(self::BASE.'/ventes', $data)->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame('1500.00', $order->total);
        $this->assertSame('1000.00', $order->discount_amount);
    }

    public function test_price_change_requires_reconfirmation_and_unavailable_stock_is_rejected(): void
    {
        $f = $this->fixture();
        $data = $this->saleData($f);
        $f['variant']->forceFill(['price' => '3000.00'])->save();
        $this->postJson(self::BASE.'/ventes', $data)->assertUnprocessable();
        $f['stock']->forceFill(['physical_quantity' => 0])->save();
        $this->postJson(self::BASE.'/panier', ['operation' => 'add', 'variant_id' => $f['variant']->id])->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    public function test_insufficient_cash_rolls_back_order_payment_and_reservation(): void
    {
        $f = $this->fixture();
        $data = $this->saleData($f);
        $data['cash_received'] = '1';
        $this->postJson(self::BASE.'/ventes', $data)->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, (int) $f['stock']->fresh()->reserved_quantity);
        $this->assertSame(10, (int) $f['stock']->fresh()->physical_quantity);
    }

    public function test_forbidden_roles_and_inactive_users_cannot_open_pos(): void
    {
        foreach (['DELIVERY_AGENT', 'SUPER_ADMIN'] as $role) {
            $f = $this->fixture($role);
            $this->get(self::BASE)->assertForbidden();
            $this->postJson(self::BASE.'/panier', ['operation' => 'clear'])->assertForbidden();
        }
        $buyer = User::factory()->create(['is_buyer' => true]);
        $this->actingAs($buyer, 'web')->get(self::BASE)->assertForbidden();
        $f = $this->fixture();
        $f['actor']->forceFill(['status' => 'INACTIVE'])->save();
        $this->actingAs($f['actor'], 'web')->get(self::BASE)->assertForbidden();
    }

    public function test_foreign_products_sessions_sales_and_documents_are_inaccessible(): void
    {
        $a = $this->fixture();
        $data = $this->saleData($a);
        $this->postJson(self::BASE.'/ventes', $data)->assertCreated();
        $sale = Order::firstOrFail();
        $b = $this->fixture();
        $this->postJson(self::BASE.'/panier', ['operation' => 'add', 'variant_id' => $a['variant']->id])->assertUnprocessable();
        $this->get(self::BASE.'/ventes')->assertOk()->assertDontSee($sale->order_number);
        $this->get(self::BASE.'/ventes/'.$sale->id)->assertNotFound();
        $this->get(self::BASE.'/ventes/'.$sale->id.'/document?format=A4')->assertNotFound();
        $this->get(self::BASE.'/ventes/'.$sale->id.'/document?format=A4&download=pdf')->assertNotFound();
        $data = $this->saleData($b);
        $data['session_id'] = $a['session']->id;
        $this->postJson(self::BASE.'/ventes', $data)->assertNotFound();
    }

    public function test_manager_can_record_unpaid_but_cannot_inherit_cash_confirmation_or_document_rights(): void
    {
        $f = $this->fixture('STORE_MANAGER');
        $data = $this->saleData($f);
        $this->postJson(self::BASE.'/ventes', $data)->assertForbidden();
        $data['mode'] = 'UNPAID';
        $this->postJson(self::BASE.'/ventes', $data)->assertCreated();
        $this->get(self::BASE.'/ventes/'.Order::first()->id.'/document?format=A4')->assertForbidden();
    }

    public function test_document_snapshots_survive_catalog_and_seller_edits_and_pdf_is_a4(): void
    {
        $f = $this->fixture();
        $this->postJson(self::BASE.'/ventes', $this->saleData($f))->assertCreated();
        $order = Order::firstOrFail();
        $f['product']->forceFill(['name' => 'Changed product'])->save();
        $f['store']->forceFill(['name' => 'Changed seller'])->save();
        $this->get(self::BASE.'/ventes/'.$order->id.'/document?format=A4')->assertOk()->assertSee('Fictional POS store')->assertSee('Fictional keyboard')->assertDontSee('Changed product')->assertDontSee('Changed seller');
        foreach (['58', '80'] as $format) {
            $this->get(self::BASE.'/ventes/'.$order->id.'/document?format='.$format)->assertOk()->assertSee('data-format="'.$format.'"', false);
        }
        $pdf = $this->get(self::BASE.'/ventes/'.$order->id.'/document?format=A4&download=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+595\.\d+\s+841\.\d+\s*\]/', $pdf->getContent());
        if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
            $directory = storage_path('demo/phase19b-preview');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/invoice.pdf', $pdf->getContent());
            foreach (['A4', '58', '80'] as $format) {
                file_put_contents($directory.'/document-'.$format.'.html', $this->get(self::BASE.'/ventes/'.$order->id.'/document?format='.$format)->getContent());
            }
        }
    }

    public function test_document_disabling_is_enforced_on_previews_and_downloads(): void
    {
        $f = $this->fixture();
        $this->postJson(self::BASE.'/ventes', $this->saleData($f))->assertCreated();
        $order = Order::firstOrFail();
        $this->get(self::BASE.'/ventes?to=2026-12-31')->assertOk();
        StoreDocumentSetting::create(['digital_invoices_enabled' => false, 'digital_receipts_enabled' => false]);
        foreach (['format=A4', 'format=58', 'format=A4&download=pdf'] as $query) {
            $this->get(self::BASE.'/ventes/'.$order->id.'/document?'.$query)->assertForbidden();
        }
    }

    public function test_owner_operations_and_document_settings_are_rendered_under_real_permissions(): void
    {
        $this->fixture('STORE_OWNER');
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            $page = $this->get(self::BASE)->assertOk()->assertSee(__('pos_interface.create_register'))->assertSee(__('pos_interface.document_settings'));
            if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                $directory = storage_path('demo/phase19b-preview');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($directory.'/owner-'.$locale.'.html', $page->getContent());
            }
        }
    }

    public function test_bilingual_cashier_views_can_be_rendered_for_offline_chrome(): void
    {
        $f = $this->fixture();
        $this->postJson(self::BASE.'/ventes', $this->saleData($f))->assertCreated();
        $sale = Order::firstOrFail();
        $this->get(self::BASE)->assertOk();
        $this->postJson(self::BASE.'/panier', ['operation' => 'add', 'variant_id' => $f['variant']->id])->assertOk();
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            $page = $this->get(self::BASE)->assertOk()->assertSee(__('pos_interface.title'));
            if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                $directory = storage_path('demo/phase19b-preview');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($directory.'/pos-'.$locale.'.html', $page->getContent());
                file_put_contents($directory.'/history-'.$locale.'.html', $this->get(self::BASE.'/ventes')->assertOk()->getContent());
                file_put_contents($directory.'/sale-'.$locale.'.html', $this->get(self::BASE.'/ventes/'.$sale->id)->assertOk()->getContent());
                $response = $this->postJson(self::BASE.'/panier', ['operation' => 'set', 'variant_id' => $f['variant']->id, 'quantity' => 2])->assertOk();
                file_put_contents($directory.'/cart-'.$locale.'.json', $response->getContent());
                $this->postJson(self::BASE.'/panier', ['operation' => 'set', 'variant_id' => $f['variant']->id, 'quantity' => 1])->assertOk();
            }
        }
    }
}
