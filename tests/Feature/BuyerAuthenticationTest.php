<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BuyerAuthenticationTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    private const PASSWORD = 'BuyerTestPassword123!';

    protected function refreshTestDatabase(): void
    {
        if ($this->app->configurationIsCached()
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Buyer tests require uncached SQLite :memory: exclusively.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshSafeDatabase();
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Acheteur Test', 'email' => 'buyer@example.test', 'phone' => '690000001',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    private function buyer(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'is_buyer' => true, 'store_id' => null, 'password' => self::PASSWORD,
        ], $attributes));
    }

    private function professional(string $code = 'STORE_OWNER'): User
    {
        $store = Store::create(['name' => 'Boutique Test', 'slug' => 'shop-'.strtolower($code), 'status' => 'ACTIVE']);
        $user = User::factory()->create([
            'store_id' => $store->id, 'is_buyer' => false, 'password' => self::PASSWORD,
            'email_verified_at' => now(), 'phone' => '690000099',
        ]);
        $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_registration_creates_only_a_global_buyer_with_a_hashed_password(): void
    {
        $this->post(route('buyer.register.store'), $this->registration([
            'email' => '  BUYER@Example.Test  ', 'name' => ' Acheteur Test ',
            'store_id' => 123, 'status' => 'INACTIVE', 'roles' => ['SUPER_ADMIN'],
            'email_verified_at' => now()->toDateTimeString(), 'is_buyer' => false,
        ]))->assertRedirect(route('buyer.account'));

        $user = User::where('email', 'buyer@example.test')->sole();
        $this->assertTrue($user->is_buyer);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertNull($user->store_id);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('Acheteur Test', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertSame(0, $user->roles()->count());
        $this->assertAuthenticatedAs($user, 'buyer');
        $this->assertGuest('web');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_existing_professional_email_is_rejected_case_insensitively_without_changes(): void
    {
        $user = $this->professional();
        $before = $user->fresh()->getRawOriginal();
        $this->postJson(route('buyer.register.store'), $this->registration([
            'email' => strtoupper($user->email),
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest('buyer');
    }

    public function test_invalid_registration_does_not_create_a_user(): void
    {
        $this->postJson(route('buyer.register.store'), $this->registration([
            'name' => '', 'email' => 'invalid', 'password' => 'short',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_matching_customer_details_never_link_or_modify_a_store_customer(): void
    {
        $store = Store::create(['name' => 'Marchand', 'slug' => 'marchand', 'status' => 'ACTIVE']);
        app(TenantContext::class)->setFromStore($store);
        $customer = Customer::create(['name' => 'Acheteur Test', 'phone' => '690000001', 'email' => 'buyer@example.test', 'status' => 'ACTIVE']);
        $before = $customer->fresh()->getRawOriginal();
        $this->post(route('buyer.register.store'), $this->registration())->assertRedirect(route('buyer.account'));
        $this->assertSame($before, $customer->fresh()->getRawOriginal());
        $this->assertDatabaseCount('customers', 1);
        $this->assertNull(User::where('email', 'buyer@example.test')->sole()->store_id);
    }

    public function test_login_rotates_session_and_csrf_without_authenticating_the_professional_guard(): void
    {
        $user = $this->buyer(['email' => 'MixedCase@Example.Test']);
        $this->withSession(['locale' => 'en', 'zemboa.cart' => ['store_id' => 1, 'items' => [1 => 2]]]);
        $beforeId = session()->getId();
        $beforeToken = session()->token();
        $this->post(route('buyer.login.store'), [
            'email' => ' mixedcase@example.test ', 'password' => self::PASSWORD,
        ])->assertRedirect(route('buyer.account'))->assertSessionHas('locale', 'en')
            ->assertSessionHas('zemboa.cart.items.1', 2);
        $this->assertNotSame($beforeId, session()->getId());
        $this->assertNotSame($beforeToken, session()->token());
        $this->assertAuthenticatedAs($user, 'buyer');
        $this->assertGuest('web');
    }

    public function test_wrong_credentials_and_inactive_accounts_are_rejected(): void
    {
        $user = $this->buyer();
        $this->postJson(route('buyer.login.store'), ['email' => $user->email, 'password' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $user->forceFill(['status' => 'INACTIVE'])->save();
        $this->postJson(route('buyer.login.store'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->assertGuest('buyer');
    }

    public function test_global_null_store_user_is_not_automatically_a_buyer(): void
    {
        $user = $this->buyer(['is_buyer' => false]);
        $this->postJson(route('buyer.login.store'), ['email' => $user->email, 'password' => self::PASSWORD, 'enable_buyer' => true])
            ->assertUnprocessable();
        $this->assertFalse($user->fresh()->is_buyer);
    }

    public function test_professional_opt_in_requires_correct_credentials_and_explicit_consent(): void
    {
        $user = $this->professional();
        $roles = $user->roles()->pluck('roles.id')->all();
        $this->postJson(route('buyer.login.store'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertUnprocessable();
        $this->postJson(route('buyer.login.store'), ['email' => $user->email, 'password' => 'wrong', 'enable_buyer' => true])
            ->assertUnprocessable();
        $this->assertFalse($user->fresh()->is_buyer);
        $this->post(route('buyer.login.store'), ['email' => $user->email, 'password' => self::PASSWORD, 'enable_buyer' => true])
            ->assertRedirect(route('buyer.account'));
        $this->assertTrue($user->fresh()->is_buyer);
        $this->assertSame($roles, $user->roles()->pluck('roles.id')->all());
        $this->assertNotNull($user->fresh()->store_id);
        $this->assertAuthenticatedAs($user, 'buyer');
        $this->assertGuest('web');
        $this->getJson('/orders')->assertUnauthorized();
    }

    public function test_admin_cannot_enable_buyer_access_even_with_the_flag_set(): void
    {
        $user = $this->buyer();
        $role = Role::firstOrCreate(['code' => 'SUPER_ADMIN'], ['name' => 'Administrateur']);
        $user->roles()->attach($role);
        $this->postJson(route('buyer.login.store'), ['email' => $user->email, 'password' => self::PASSWORD, 'enable_buyer' => true])
            ->assertUnprocessable();
        $this->assertGuest('buyer');
    }

    public function test_buyer_and_professional_sessions_can_coexist_and_buyer_logout_preserves_web_login(): void
    {
        $professional = $this->professional();
        $buyer = $this->buyer();
        $this->postJson(route('login.store'), ['email' => $professional->email, 'password' => self::PASSWORD])->assertOk();
        $this->post(route('buyer.login.store'), ['email' => $buyer->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($professional, 'web');
        $this->assertAuthenticatedAs($buyer, 'buyer');
        $oldId = session()->getId();
        $oldToken = session()->token();
        $this->post(route('buyer.logout'))->assertRedirect(route('buyer.login'));
        $this->assertGuest('buyer');
        $this->assertAuthenticatedAs($professional, 'web');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->getJson(route('account.show'))->assertOk()->assertJsonPath('id', $professional->id);
        $this->get(route('buyer.account'))->assertRedirect(route('buyer.login'));
    }

    public function test_guests_and_professional_only_sessions_cannot_open_the_buyer_account(): void
    {
        $this->get(route('buyer.account'))->assertRedirect(route('buyer.login'));
        $this->getJson(route('buyer.account'))->assertUnauthorized();
        $this->actingAs($this->professional(), 'web');
        $this->get(route('buyer.account'))->assertRedirect(route('buyer.login'));
    }

    public function test_buyer_account_never_exposes_another_buyer_or_any_tenant_data(): void
    {
        $own = $this->buyer(['name' => 'Mon identité']);
        $other = $this->buyer(['name' => 'Identité privée tierce']);
        $this->actingAs($own, 'buyer');
        Auth::shouldUse('web');
        $this->get(route('buyer.account', ['user_id' => $other->id, 'store_id' => 123]))
            ->assertOk()->assertSee($own->email)->assertDontSee($other->email)->assertDontSee($other->name);
        foreach (['/account', '/orders', '/customers', '/stock', '/store/settings', '/pos/registers'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_disabled_or_revoked_buyer_sessions_lose_access(): void
    {
        $user = $this->buyer();
        $this->actingAs($user, 'buyer');
        Auth::shouldUse('web');
        $this->get(route('buyer.account'))->assertOk();
        $user->forceFill(['is_buyer' => false])->save();
        Auth::guard('buyer')->forgetUser();
        $this->get(route('buyer.account'))->assertRedirect(route('buyer.login'));
        $this->assertGuest('buyer');
    }

    public function test_disabled_buyer_session_is_rejected_without_changing_professional_roles(): void
    {
        $user = $this->buyer();
        $this->post(route('buyer.login.store'), ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect();
        $user->forceFill(['status' => 'INACTIVE'])->save();
        Auth::guard('buyer')->forgetUser();
        $this->getJson(route('buyer.account'))->assertUnauthorized();
        $this->assertGuest('buyer');
    }

    public function test_dual_identity_cannot_use_buyer_login_to_cross_a_store_boundary(): void
    {
        $owner = $this->professional();
        $other = $this->professional('STORE_MANAGER');
        $this->post(route('buyer.login.store'), ['email' => $owner->email, 'password' => self::PASSWORD, 'enable_buyer' => true])
            ->assertRedirect();
        $this->getJson('/customers/1')->assertUnauthorized();
        $this->postJson(route('login.store'), ['email' => $owner->email, 'password' => self::PASSWORD])->assertOk();
        app(TenantContext::class)->setFromStore($other->store);
        $customer = Customer::create(['name' => 'Client privé autre boutique', 'phone' => '699000001', 'status' => 'ACTIVE']);
        $this->getJson('/customers/'.$customer->id)->assertNotFound();
        $this->getJson(route('account.show'))->assertOk()->assertJsonPath('store_id', $owner->store_id);
    }

    public function test_buyer_cannot_log_in_through_professional_or_admin_login(): void
    {
        $user = $this->buyer();
        foreach (['login.store', 'admin.login.store'] as $route) {
            $this->postJson(route($route), ['email' => $user->email, 'password' => self::PASSWORD])->assertUnprocessable();
            $this->assertGuest('web');
        }
    }

    public function test_login_and_registration_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('buyer.login.store'), ['email' => 'absent@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('buyer.login.store'), ['email' => 'absent@example.test', 'password' => 'wrong'])->assertTooManyRequests();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('buyer.register.store'), [])->assertUnprocessable();
        }
        $this->postJson(route('buyer.register.store'), [])->assertTooManyRequests();
    }

    public function test_demo_login_throttle_persists_when_cache_instances_are_recreated(): void
    {
        $originalStorage = storage_path();
        // File cache only, in a unique test directory; database stays SQLite :memory:.
        $this->app->useStoragePath(storage_path('framework/testing/buyer-limiter-'.bin2hex(random_bytes(8))));
        try {
            config(['cache' => require base_path('bootstrap/demo-cache.php'), 'session.block_store' => 'demo_limiter']);
            $this->assertSame('array', config('cache.default'));
            $this->assertSame('file', config('cache.stores.demo_limiter.driver'));
            $this->assertSame(['array', 'demo_limiter'], array_keys(config('cache.stores')));
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $this->app->forgetInstance('cache');
                $this->app->forgetInstance('cache.store');
                $this->app->forgetInstance(RateLimiter::class);
                \Illuminate\Support\Facades\RateLimiter::clearResolvedInstance(RateLimiter::class);
                $this->app->getProvider(AppServiceProvider::class)->boot();
                $response = $this->postJson(route('buyer.login.store'), [
                    'email' => 'limiter-test@example.test', 'password' => 'incorrect',
                ]);
                $attempt < 5 ? $response->assertUnprocessable() : $response->assertTooManyRequests();
            }
        } finally {
            $this->app->useStoragePath($originalStorage);
        }
    }

    public function test_authentication_pages_and_validation_are_bilingual_and_never_redisplay_passwords(): void
    {
        foreach (['fr' => 'Créez votre compte ZEMBOA', 'en' => 'Create your ZEMBOA account'] as $locale => $title) {
            $this->withSession(['locale' => $locale])->get(route('buyer.register'))->assertOk()->assertSee($title);
            $this->get(route('buyer.login'))->assertOk()->assertSee('name="_token"', false);
        }
        $this->withSession(['locale' => 'en'])->postJson(route('buyer.register.store'), $this->registration(['password' => 'short']))
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Your password must contain between 12 and 255 characters.');
        $this->from(route('buyer.login'))->post(route('buyer.login.store'), ['email' => 'missing@example.test', 'password' => self::PASSWORD])
            ->assertRedirect(route('buyer.login'));
        $this->get(route('buyer.login'))->assertDontSee(self::PASSWORD);
    }

    public function test_sensitive_posts_require_csrf_and_logout_has_no_get_route(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        foreach (['buyer.register.store', 'buyer.login.store', 'buyer.logout'] as $route) {
            $this->post(route($route), [])->assertStatus(419);
        }
        $this->post(route('buyer.register.store'), $this->registration(['_token' => session()->token()]))
            ->assertRedirect(route('buyer.account'));
        $this->post(route('buyer.logout'), ['_token' => session()->token()])->assertRedirect(route('buyer.login'));
        $this->get(route('buyer.logout'))->assertStatus(405);
    }

    public function test_registration_and_buyer_account_do_not_create_orders_or_payments(): void
    {
        $this->post(route('buyer.register.store'), $this->registration())->assertRedirect();
        $this->get(route('buyer.account'))->assertOk();
        foreach (['orders', 'payments', 'stock_reservations', 'role_user', 'customers'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_phone_only_registration_is_global_and_has_no_invented_email(): void
    {
        $this->post(route('buyer.register.store'), $this->registration(['email' => null]))->assertRedirect(route('buyer.account'));
        $user = User::sole();
        $this->assertSame('+237690000001', $user->phone);
        $this->assertSame('+237690000001', $user->buyer_phone_identity);
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->store_id);
        $this->assertCount(0, $user->roles);
        $this->assertArrayNotHasKey('buyer_phone_identity', $user->toArray());
        $this->get(route('buyer.account'))->assertOk();
        $this->get(route('buyer.profile'))->assertOk()->assertSee('+237690000001');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_phone_formats_are_normalized_for_registration_and_duplicates_rejected(): void
    {
        foreach (['690000001', '237690000002', '+237690000003'] as $i => $phone) {
            $this->post(route('buyer.register.store'), $this->registration(['phone' => $phone, 'email' => null]))->assertRedirect();
            $this->assertSame('+23769000000'.($i + 1), Auth::guard('buyer')->user()->phone);
            Auth::guard('buyer')->logoutCurrentDevice();
        }
        $this->postJson(route('buyer.register.store'), $this->registration(['phone' => '+237 690-000-001', 'email' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('users', 3);
    }

    public function test_invalid_or_missing_phone_and_missing_password_confirmation_are_rejected(): void
    {
        foreach ([null, 'abc690000001', '+33123456789'] as $phone) {
            $this->postJson(route('buyer.register.store'), $this->registration(['phone' => $phone]))
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $data = $this->registration();
        unset($data['password_confirmation']);
        $this->postJson(route('buyer.register.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_phone_login_accepts_equivalent_formats_and_preserves_cart(): void
    {
        $user = $this->buyer(['phone' => '+237690000001', 'email' => null]);
        $this->withSession(['zemboa.cart' => ['store_id' => 5, 'items' => [12 => 3]]]);
        foreach (['690000001', '237690000001', '+237 690-000-001'] as $phone) {
            $this->post(route('buyer.login.store'), ['identifier' => $phone, 'password' => self::PASSWORD])->assertRedirect(route('buyer.account'));
            $this->assertAuthenticatedAs($user, 'buyer');
            $this->assertGuest('web');
            $this->assertSame(3, session('zemboa.cart.items.12'));
            Auth::guard('buyer')->logoutCurrentDevice();
        }
    }

    public function test_equivalent_phone_formats_share_the_login_limit(): void
    {
        $formats = ['690000001', '237690000001', '+237690000001', '+237 690 000 001', '(690)-000-001', '690000001'];
        foreach ($formats as $i => $phone) {
            $response = $this->postJson(route('buyer.login.store'), ['identifier' => $phone, 'password' => 'incorrect']);
            $i < 5 ? $response->assertUnprocessable() : $response->assertTooManyRequests();
        }
    }

    public function test_historical_professional_phone_collision_never_selects_or_changes_an_account(): void
    {
        $user = $this->professional();
        $other = User::factory()->create(['store_id' => $user->store_id, 'phone' => '+237690000099', 'is_buyer' => false]);
        $before = $user->fresh()->getRawOriginal();
        $otherBefore = $other->fresh()->getRawOriginal();
        $this->postJson(route('buyer.register.store'), $this->registration(['phone' => '237690000099']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson(route('buyer.login.store'), ['identifier' => '690000099', 'password' => self::PASSWORD, 'enable_buyer' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->postJson(route('buyer.login.store'), ['identifier' => $user->email, 'password' => self::PASSWORD, 'enable_buyer' => true])->assertUnprocessable();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertGuest('buyer');
    }

    public function test_professional_without_phone_cannot_enable_buyer_but_can_still_log_in_professionally(): void
    {
        $user = $this->professional();
        $user->forceFill(['phone' => null])->save();
        $this->postJson(route('buyer.login.store'), ['identifier' => $user->email, 'password' => self::PASSWORD, 'enable_buyer' => true])->assertUnprocessable();
        $this->assertFalse($user->fresh()->is_buyer);
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('buyer');
    }

    public function test_database_unique_identity_cannot_be_bypassed_by_phone_format_or_opt_in(): void
    {
        $this->buyer(['phone' => '+237690000001']);
        try {
            $this->buyer(['phone' => '237690000001']);
            $this->fail('Expected the database unique constraint, independently from request validation.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('users', 1);
        }
        $professional = $this->professional();
        $professional->forceFill(['phone' => '690000001'])->save();
        $this->postJson(route('buyer.login.store'), ['identifier' => $professional->email, 'password' => self::PASSWORD, 'enable_buyer' => true])->assertUnprocessable();
        $this->assertFalse($professional->fresh()->is_buyer);
    }

    public function test_registration_and_login_forms_expose_phone_optional_email_and_bilingual_identifier(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])->get(route('buyer.register'))->assertOk()
                ->assertSee('name="phone"', false)->assertSee(__('buyer.email_optional', [], $locale));
            $this->get(route('buyer.login'))->assertOk()->assertSee('name="identifier"', false)
                ->assertSee(__('buyer.identifier', [], $locale))->assertDontSee('name="phone_verified"', false);
        }
    }

    public function test_generated_identity_is_consistent_for_raw_historical_format_changes(): void
    {
        $user = $this->buyer(['phone' => '+237690000001']);
        foreach (['690000001', '237690000001', '+237 690-000-001', '(690)000.001', "+237\t690\r\n000001"] as $phone) {
            $user->forceFill(['phone' => $phone])->save();
            $this->assertSame('+237690000001', $user->fresh()->buyer_phone_identity);
            try {
                $this->buyer(['phone' => '690000001']);
                $this->fail('Unique index must protect every representation.');
            } catch (UniqueConstraintViolationException) {
                $this->assertDatabaseCount('users', 1);
            }
        }
    }

    public function test_registration_and_opt_in_fail_closed_without_the_unique_identity_index(): void
    {
        $user = $this->professional();
        Schema::table('users', fn ($table) => $table->dropUnique('users_buyer_phone_identity_unique'));
        $this->postJson(route('buyer.register.store'), $this->registration(['email' => null]))->assertStatus(503);
        $this->postJson(route('buyer.login.store'), ['identifier' => $user->email, 'password' => self::PASSWORD, 'enable_buyer' => true])->assertStatus(503);
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($user->fresh()->is_buyer);
    }

    public function test_case_ambiguous_legacy_email_never_selects_an_arbitrary_user(): void
    {
        $this->buyer(['email' => 'Case@Example.test']);
        $this->buyer(['email' => 'case@example.test']);
        $this->postJson(route('buyer.login.store'), ['identifier' => 'CASE@example.test', 'password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->assertGuest('buyer');
    }
}
