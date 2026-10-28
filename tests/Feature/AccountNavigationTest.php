<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\AccountDestination;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class AccountNavigationTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Uncached SQLite :memory: only.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
    }

    private function shop(): array
    {
        $store = Store::create(['name' => 'Fictional navigation store', 'slug' => 'navigation-'.fake()->unique()->numerify('########'), 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        StoreSetting::create(['pickup_enabled' => true, 'delivery_enabled' => false, 'allow_reservations' => true, 'reservation_duration_minutes' => 30]);
        $category = Category::create(['name' => 'Fictional category', 'slug' => 'category', 'status' => 'ACTIVE']);
        $product = Product::create(['name' => 'Fictional product', 'slug' => 'product', 'category_id' => $category->id, 'status' => 'ACTIVE']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-'.fake()->unique()->numerify('########'), 'price' => '2500', 'status' => 'ACTIVE']);
        $stock = StockLevel::firstOrCreate(['product_variant_id' => $variant->id]);
        $stock->forceFill(['physical_quantity' => 10, 'reserved_quantity' => 0])->save();

        return compact('store', 'product', 'variant', 'stock');
    }

    private function actor(string $role, Store $store): User
    {
        $actor = User::factory()->create(['name' => 'Fictional account', 'email' => fake()->unique()->numerify('account-########').'@example.test', 'store_id' => $role === 'SUPER_ADMIN' ? null : $store->id, 'status' => 'ACTIVE', 'email_verified_at' => now(), 'is_buyer' => false, 'password' => 'Fictional-password-123']);
        $actor->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $actor;
    }

    public function test_all_professional_account_routes_and_public_return_links_use_authorised_destinations(): void
    {
        $f = $this->shop();
        foreach (['SUPER_ADMIN' => 'admin.dashboard', 'STORE_OWNER' => 'professional.dashboard', 'STORE_MANAGER' => 'professional.dashboard', 'CASHIER' => 'pos.interface.index', 'DELIVERY_AGENT' => 'courier.index'] as $role => $route) {
            $actor = $this->actor($role, $f['store']);
            $this->actingAs($actor, 'web');
            $this->get('/account')->assertRedirect(route($route));
            $this->getJson('/account')->assertOk()->assertJsonPath('id', $actor->id);
            $this->get('/')->assertOk()->assertSee('href="'.route($route).'"', false);
            $this->get('/boutiques/'.$f['store']->slug)->assertOk()->assertSee('href="'.route($route).'"', false)->assertSee('workspace-preview');
        }
    }

    public function test_inactive_store_user_and_mixed_roles_keep_legitimate_forbidden_responses(): void
    {
        $f = $this->shop();
        $actor = $this->actor('STORE_OWNER', $f['store']);
        $this->actingAs($actor, 'web');
        $actor->forceFill(['status' => 'INACTIVE'])->save();
        $this->get('/account')->assertForbidden();
        $actor->forceFill(['status' => 'ACTIVE'])->save();
        $extra = Role::create(['code' => 'FUTURE_ROLE', 'name' => 'Fictional extra role']);
        $actor->roles()->attach($extra);
        $this->get('/account')->assertForbidden();
        $actor->roles()->detach($extra);
        $f['store']->update(['status' => 'INACTIVE']);
        $this->get('/account')->assertForbidden();
        $this->get('/espace-professionnel')->assertForbidden();
    }

    public function test_buyer_and_guest_account_destinations_remain_separate(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
        $this->getJson('/account')->assertUnauthorized();
        $buyer = User::factory()->create(['status' => 'ACTIVE', 'is_buyer' => true, 'store_id' => null]);
        $this->actingAs($buyer, 'buyer');
        $this->get('/account')->assertRedirect(route('buyer.account'));
        $this->get('/')->assertOk()->assertSee('data-buyer-space', false);
        $this->assertGuest('web');
    }

    public function test_missing_dashboard_permission_does_not_grant_access_or_break_professional_identity(): void
    {
        $f = $this->shop();
        $actor = $this->actor('STORE_OWNER', $f['store']);
        $actor->roles()->firstOrFail()->permissions()->detach();
        $this->postJson('/login', ['email' => $actor->email, 'password' => 'Fictional-password-123'])->assertOk();
        $this->getJson('/account')->assertOk()->assertJsonPath('id', $actor->id);
        $this->get('/account')->assertForbidden();
        $this->get('/espace-professionnel')->assertForbidden();
        $this->assertNull(AccountDestination::professionalRoute($actor));
    }

    public function test_professional_login_ignores_unrelated_intended_destination(): void
    {
        $f = $this->shop();
        foreach (['STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT'] as $role) {
            $actor = $this->actor($role, $f['store']);
            auth('web')->logout();
            $this->withSession(['url.intended' => 'https://invalid.example.test/foreign'])->post('/login', ['email' => $actor->email, 'password' => 'Fictional-password-123'])->assertRedirect(route(AccountDestination::professionalRoute($actor)));
        }
    }

    public function test_every_professional_role_is_blocked_from_public_cart_and_guest_checkout(): void
    {
        $f = $this->shop();
        $slug = $f['store']->slug;
        foreach (['SUPER_ADMIN', 'STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT'] as $role) {
            $actor = $this->actor($role, $f['store']);
            $this->actingAs($actor, 'web');
            $cart = ['store_id' => $f['store']->id, 'items' => [$f['variant']->id => 1], 'revision' => 'fictional-revision'];
            session(['zemboa.cart' => $cart]);
            $this->postJson('/shops/'.$slug.'/cart/items', ['product_variant_id' => $f['variant']->id, 'quantity' => 1])->assertForbidden();
            $this->patchJson('/shops/'.$slug.'/cart/items/'.$f['variant']->id, ['quantity' => 2])->assertForbidden();
            $this->deleteJson('/shops/'.$slug.'/cart/items/'.$f['variant']->id)->assertForbidden();
            $this->deleteJson('/shops/'.$slug.'/cart')->assertForbidden();
            $this->postJson('/shops/'.$slug.'/cart/switch', ['confirmation_token' => str_repeat('a', 64)])->assertForbidden();
            $this->get('/boutiques/'.$slug.'/commande')->assertForbidden();
            $this->postJson('/shops/'.$slug.'/checkout', [])->assertForbidden();
            $this->assertSame($cart, session('zemboa.cart'));
            $this->get('/boutiques/'.$slug.'/produits/'.$f['product']->id)->assertOk()->assertDontSee('data-cart-product', false);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(10, (int) $f['stock']->fresh()->physical_quantity);
    }

    public function test_simultaneous_guards_cannot_bypass_purchase_restriction(): void
    {
        $f = $this->shop();
        $this->actingAs($this->actor('STORE_OWNER', $f['store']), 'web');
        $buyer = User::factory()->create(['status' => 'ACTIVE', 'is_buyer' => true, 'store_id' => null]);
        $this->actingAs($buyer, 'buyer');
        $this->get('/')->assertOk()->assertSee('data-professional-space', false)->assertSee('data-buyer-space', false);
        $this->postJson('/shops/'.$f['store']->slug.'/cart/items', ['product_variant_id' => $f['variant']->id, 'quantity' => 1])->assertForbidden();
        $this->get('/boutiques/'.$f['store']->slug.'/commande')->assertForbidden();
        $this->assertAuthenticatedAs($buyer, 'buyer');
    }

    public function test_professional_login_explicitly_uses_web_without_replacing_buyer_identity(): void
    {
        $f = $this->shop();
        $professional = $this->actor('STORE_OWNER', $f['store']);
        $buyer = User::factory()->create(['status' => 'ACTIVE', 'is_buyer' => true, 'store_id' => null]);
        $this->actingAs($buyer, 'buyer');
        $this->postJson('/login', ['email' => $professional->email, 'password' => 'Fictional-password-123'])->assertOk();
        $this->assertAuthenticatedAs($professional, 'web');
        $this->assertAuthenticatedAs($buyer, 'buyer');
    }

    public function test_public_guest_and_buyer_can_still_add_and_open_checkout(): void
    {
        $f = $this->shop();
        $slug = $f['store']->slug;
        $this->postJson('/shops/'.$slug.'/cart/items', ['product_variant_id' => $f['variant']->id, 'quantity' => 1])->assertOk();
        $this->get('/boutiques/'.$slug.'/commande')->assertOk();
        $buyer = User::factory()->create(['status' => 'ACTIVE', 'is_buyer' => true, 'store_id' => null]);
        $this->actingAs($buyer, 'buyer');
        $this->patchJson('/shops/'.$slug.'/cart/items/'.$f['variant']->id, ['quantity' => 2])->assertOk();
        $this->get('/boutiques/'.$slug.'/commande')->assertOk();
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_authentication_and_invitation_views_are_bilingual_and_can_be_previewed_offline(): void
    {
        $f = $this->shop();
        $owner = $this->actor('STORE_OWNER', $f['store']);
        $owner->forceFill(['status' => 'INVITED', 'email_verified_at' => null])->save();
        $employee = $this->actor('CASHIER', $f['store']);
        $employee->forceFill(['status' => 'INVITED', 'email_verified_at' => null])->save();
        $ownerToken = Password::broker('users')->createToken($owner);
        $employeeToken = Password::broker('users')->createToken($employee);
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            $paths = ['buyer-login' => '/client/connexion', 'buyer-register' => '/client/inscription', 'professional' => '/login', 'admin' => '/admin/login', 'owner-invite' => route('owner.invitation.form', ['token' => $ownerToken, 'email' => $owner->email]), 'employee-invite' => route('employee.invitation.form', ['token' => $employeeToken, 'email' => $employee->email])];
            foreach ($paths as $kind => $path) {
                $page = $this->get($path)->assertOk()->assertSee('data-password-toggle', false);
                if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                    $directory = storage_path('demo/phase20c1-preview');
                    if (! is_dir($directory)) {
                        mkdir($directory, 0700, true);
                    }
                    $html = $page->getContent();
                    $html = str_replace([$ownerToken, $employeeToken, $owner->email, $employee->email], ['REDACTED-TOKEN', 'REDACTED-TOKEN', 'invite@example.test', 'invite@example.test'], $html);
                    $html = str_replace(csrf_token(), 'REDACTED-CSRF', $html);
                    file_put_contents($directory.'/'.$kind.'-'.$locale.'.html', $html);
                }
            }
            if (getenv('ZEMBOA_RENDER_FIXTURES') === '1') {
                $professional = $this->actor('STORE_OWNER', $f['store']);
                $professional->forceFill(['preferred_locale' => $locale])->save();
                $this->actingAs($professional, 'web');
                foreach (['marketplace' => '/', 'storefront' => '/boutiques/'.$f['store']->slug] as $kind => $path) {
                    $html = $this->get($path)->assertOk()->getContent();
                    file_put_contents($directory.'/'.$kind.'-'.$locale.'.html', str_replace(csrf_token(), 'REDACTED-CSRF', $html));
                }
                auth('web')->logout();
            }
        }
    }

    public function test_invitation_status_uses_current_facts_without_exposing_the_broker_hash(): void
    {
        $f = $this->shop();
        $f['store']->update(['status' => 'PENDING']);
        $owner = $this->actor('STORE_OWNER', $f['store']);
        $owner->forceFill(['status' => 'INVITED', 'email_verified_at' => null])->save();
        $token = Password::broker('users')->createToken($owner);
        $hash = DB::table(config('auth.passwords.users.table'))->where('email', $owner->email)->value('token');
        $this->actingAs($this->actor('SUPER_ADMIN', $f['store']), 'web');
        $this->get(route('admin.stores.show', $f['store']->id))->assertOk()
            ->assertSee('sa-invitation-facts')->assertDontSee($token)->assertDontSee($hash);
    }

    public function test_unavailable_invitation_pages_are_localised_and_hide_sensitive_urls(): void
    {
        foreach (['fr', 'en'] as $locale) {
            foreach (['owner', 'employee'] as $type) {
                $this->withSession(['locale' => $locale])->get('/'.$type.'-invitations/fictional-sensitive-token?email=private%40example.test')
                    ->assertNotFound()->assertHeader('Referrer-Policy', 'no-referrer')
                    ->assertSee(__('access.invalid', [], $locale))->assertDontSee('fictional-sensitive-token')->assertDontSee('private@example.test');
            }
        }
    }
}
