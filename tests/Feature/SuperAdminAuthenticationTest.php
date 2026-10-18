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

class SuperAdminAuthenticationTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    private const PASSWORD = 'ZemboaAdminSecure123!';

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

    private function createUser(
        string $role = 'SUPER_ADMIN',
        string $status = 'ACTIVE',
        bool $withStore = false
    ): User {
        $storeId = null;

        if ($withStore) {
            $store = Store::create([
                'name' => 'Boutique Test Admin',
                'slug' => 'admin-' . Str::lower(Str::random(12)),
                'status' => 'ACTIVE',
            ]);

            $storeId = $store->id;
        }

        $user = User::factory()->create([
            'store_id' => $storeId,
            'status' => $status,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ]);

        $roleModel = Role::query()
            ->where('code', $role)
            ->firstOrFail();

        $user->roles()->attach($roleModel->id);

        return $user;
    }

    private function login(User $user, string $password = self::PASSWORD)
    {
        return $this->postJson(route('admin.login.store'), [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    public function test_admin_login_page_is_accessible(): void
    {
        $this->get(route('admin.login'))->assertOk();
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->getJson(route('admin.dashboard'))
            ->assertUnauthorized();
    }

    public function test_active_super_admin_can_login(): void
    {
        $admin = $this->createUser();

        $this->login($admin)
            ->assertOk()
            ->assertJsonPath('admin.id', $admin->id);

        $this->assertAuthenticatedAs($admin);

        $this->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_inactive_super_admin_cannot_login(): void
    {
        $admin = $this->createUser(
            'SUPER_ADMIN',
            'INACTIVE'
        );

        $this->login($admin)->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $admin = $this->createUser();

        $this->login($admin, 'WrongPassword123!')
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_store_owner_cannot_login_as_admin(): void
    {
        $owner = $this->createUser(
            'STORE_OWNER',
            'ACTIVE',
            true
        );

        $this->login($owner)->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_super_admin_linked_to_store_is_rejected(): void
    {
        $admin = $this->createUser(
            'SUPER_ADMIN',
            'ACTIVE',
            true
        );

        $this->login($admin)->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_mixed_admin_and_cashier_roles_are_rejected(): void
    {
        $admin = $this->createUser();

        $cashier = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $admin->roles()->attach($cashier->id);

        $this->login($admin)->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_store_owner_session_cannot_access_admin(): void
    {
        $owner = $this->createUser(
            'STORE_OWNER',
            'ACTIVE',
            true
        );

        $this->actingAs($owner);

        $this->getJson(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_logout_ends_session(): void
    {
        $admin = $this->createUser();

        $this->login($admin)->assertOk();

        $this->postJson(route('admin.logout'))
            ->assertOk();

        $this->assertGuest();

        $this->getJson(route('admin.dashboard'))
            ->assertUnauthorized();
    }

    public function test_disabled_admin_session_is_blocked(): void
    {
        $admin = $this->createUser();

        $this->login($admin)->assertOk();

        $this->get(route('admin.dashboard'))
            ->assertOk();

        // Désactivation après connexion.
        $admin->status = 'INACTIVE';
        $admin->save();

        // Forcer le rechargement de l'utilisateur
        // depuis la session entre deux requêtes.
        auth('web')->forgetUser();

        $this->getJson(route('admin.dashboard'))
            ->assertForbidden();
    }
}
