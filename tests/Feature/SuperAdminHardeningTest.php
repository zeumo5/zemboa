<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SuperAdminHardeningTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as protected refreshIsolatedDatabase; }

    public function refreshDatabase(): void
    {
        if ($this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Only uncached SQLite :memory: allowed.');
        }
        config(['database.connections' => ['sqlite' => config('database.connections.sqlite')]]);
        $this->refreshIsolatedDatabase();
    }

    private function administrator(): User
    {
        $role = Role::firstOrCreate(['code' => 'SUPER_ADMIN'], ['name' => 'Administrator']);
        $user = User::factory()->create(['status' => 'ACTIVE', 'store_id' => null, 'is_buyer' => false, 'password' => 'Fictional-test-password-123']);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_exclusive_admin_can_login_and_access_dashboard(): void
    {
        $user = $this->administrator();
        $this->assertTrue($user->canUseSuperAdminAccount());
        $this->postJson('/admin/login', ['email' => $user->email, 'password' => 'Fictional-test-password-123'])->assertOk();
        $this->get('/admin')->assertOk();
    }

    public function test_every_additional_role_including_unknown_roles_blocks_login_and_direct_access(): void
    {
        foreach (['STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT', 'FUTURE_ROLE'] as $code) {
            $user = $this->administrator();
            $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
            $user->roles()->attach($role);
            $this->assertFalse($user->canUseSuperAdminAccount());
            $this->postJson('/admin/login', ['email' => $user->email, 'password' => 'Fictional-test-password-123'])->assertUnprocessable();
            $this->assertGuest('web');
            $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
            $this->postJson('/admin/stores', ['name' => 'Blocked'])->assertForbidden();
            Auth::guard('web')->logout();
        }
    }

    public function test_inactive_suspended_invited_and_store_attached_admins_are_rejected(): void
    {
        foreach (['INACTIVE', 'SUSPENDED', 'INVITED'] as $status) {
            $user = $this->administrator();
            $user->forceFill(['status' => $status])->save();
            $this->postJson('/admin/login', ['email' => $user->email, 'password' => 'Fictional-test-password-123'])->assertUnprocessable();
            $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
            Auth::guard('web')->logout();
        }
        $store = Store::create(['name' => 'Fictional', 'slug' => 'fictional', 'status' => 'ACTIVE']);
        $user = $this->administrator();
        $user->forceFill(['store_id' => $store->id])->save();
        $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
    }

    public function test_buyer_guard_cannot_supply_even_an_admin_identity_to_admin_routes(): void
    {
        $user = $this->administrator();
        $this->actingAs($user, 'buyer');
        Auth::shouldUse('web');
        $this->getJson('/admin')->assertUnauthorized();
    }

    public function test_buyer_flag_prevents_admin_login_and_access_even_with_exclusive_admin_role(): void
    {
        $user = $this->administrator();
        $user->forceFill(['is_buyer' => true])->save();
        $this->assertFalse($user->canUseSuperAdminAccount());
        $this->postJson('/admin/login', ['email' => $user->email, 'password' => 'Fictional-test-password-123'])
            ->assertUnprocessable();
        $this->assertGuest('web');
        $this->actingAs($user, 'web')->get('/admin')->assertForbidden();
        $this->postJson('/admin/stores', ['name' => 'Forbidden'])->assertForbidden();
    }
}
