<?php

namespace Tests\Feature;


use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EmployeeAuthenticationTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    private const PASSWORD = 'ZemboaSecure123!';

    protected function refreshTestDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException(
                'Tests interdits hors SQLite :memory:.'
            );
        }

        $this->refreshSafeDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function createStore(
        string $status = 'ACTIVE'
    ): Store {
        return Store::create([
            'name' => 'Boutique Auth ZEMBOA',
            'slug' => 'auth-' . Str::lower(Str::random(12)),
            'status' => $status,
        ]);
    }

    private function createAccount(
        string $role = 'STORE_OWNER',
        string $userStatus = 'ACTIVE',
        bool $verified = true,
        string $storeStatus = 'ACTIVE'
    ): User {
        $store = $this->createStore($storeStatus);

        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => $userStatus,
            'password' => self::PASSWORD,
            'email_verified_at' => $verified ? now() : null,
        ]);

        $roleModel = Role::query()
            ->where('code', $role)
            ->firstOrFail();

        $user->roles()->attach($roleModel->id);

        return $user;
    }

    private function login(User $user, string $password = self::PASSWORD)
    {
        return $this->postJson(route('login.store'), [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    public function test_login_page_is_accessible(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_owner_can_log_in(): void
    {
        $owner = $this->createAccount();

        $this->login($owner)
            ->assertOk()
            ->assertJsonPath('user.id', $owner->id);

        $this->assertAuthenticatedAs($owner);

        $this->getJson(route('account.show'))
            ->assertOk()
            ->assertJsonPath('store_id', $owner->store_id);
    }

    public function test_active_cashier_can_log_in(): void
    {
        $cashier = $this->createAccount('CASHIER');

        $this->login($cashier)->assertOk();

        $this->assertAuthenticatedAs($cashier);

        $this->getJson(route('account.show'))
            ->assertOk()
            ->assertJsonPath('email', $cashier->email);
    }

    public function test_invited_employee_cannot_log_in(): void
    {
        $employee = $this->createAccount(
            'STORE_MANAGER',
            'INVITED',
            false
        );

        $this->login($employee)
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_unverified_employee_cannot_log_in(): void
    {
        $employee = $this->createAccount(
            'CASHIER',
            'ACTIVE',
            false
        );

        $this->login($employee)
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_inactive_employee_cannot_log_in(): void
    {
        $employee = $this->createAccount(
            'DELIVERY_AGENT',
            'INACTIVE'
        );

        $this->login($employee)
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_inactive_store_blocks_login(): void
    {
        $owner = $this->createAccount(
            'STORE_OWNER',
            'ACTIVE',
            true,
            'INACTIVE'
        );

        $this->login($owner)
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $owner = $this->createAccount();

        $this->login($owner, 'WrongPassword123!')
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_super_admin_cannot_use_store_login(): void
    {
        $admin = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
            'password' => self::PASSWORD,
        ]);

        $role = Role::where('code', 'SUPER_ADMIN')
            ->firstOrFail();

        $admin->roles()->attach($role->id);

        $this->login($admin)
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_guest_cannot_access_account(): void
    {
        $this->getJson(route('account.show'))
            ->assertUnauthorized();
    }

    public function test_logout_ends_authenticated_session(): void
    {
        $owner = $this->createAccount();

        $this->login($owner)->assertOk();

        $this->assertAuthenticatedAs($owner);

        $this->postJson(route('logout'))
            ->assertOk();

        $this->assertGuest();

        $this->getJson(route('account.show'))
            ->assertUnauthorized();
    }

    public function test_disabled_employee_session_loses_access(): void
    {
        $employee = $this->createAccount('STORE_MANAGER');

        $this->login($employee)->assertOk();

        $this->getJson(route('account.show'))
            ->assertOk();

        $employee->status = 'INACTIVE';
        $employee->save();

        // Simuler une nouvelle requête HTTP :
        // oublier l'utilisateur mis en cache par
        // le guard, tout en conservant la session.
        auth('web')->forgetUser();

        // Le middleware tenant doit maintenant
        // relire le statut INACTIVE depuis la base.
        $this->getJson(route('account.show'))
            ->assertForbidden();

        // La session existe encore, mais le tenant
        // doit refuser les accès privés.
        $this->getJson(route('account.show'))
            ->assertForbidden();
    }
}
