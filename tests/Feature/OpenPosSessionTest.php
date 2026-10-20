<?php
namespace Tests\Feature;


use App\Actions\Pos\OpenPosSession;
use App\Models\Permission;
use App\Models\PosRegister;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class OpenPosSessionTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

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

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function createStore(string $slug = 'alpha'): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function createActor(
        Store $store,
        string $roleCode = 'CASHIER'
    ): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createRegister(
        Store $store,
        string $code = 'CAISSE-01'
    ): PosRegister {
        app(TenantContext::class)->setFromStore($store);

        return PosRegister::create([
            'name' => 'Caisse ' . $code,
            'code' => $code,
        ])->refresh();
    }

    private function open(
        Store $store,
        PosRegister $register,
        User $actor,
        string $amount = '5000.00'
    ) {
        app(TenantContext::class)->setFromStore($store);

        $this->actingAs($actor);

        return app(OpenPosSession::class)->execute(
            $register->id,
            $amount,
            $actor
        );
    }

    public function test_cashier_can_open_active_register(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $session = $this->open(
            $store,
            $register,
            $cashier
        );

        $this->assertSame('OPEN', $session->status);
        $this->assertSame(1, $session->open_slot);
        $this->assertSame('5000.00', $session->opening_cash);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'store_id' => $store->id,
            'pos_register_id' => $register->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'OPEN',
        ]);
    }

    public function test_owner_and_manager_can_open_registers(): void
    {
        $store = $this->createStore();

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $manager = $this->createActor(
            $store,
            'STORE_MANAGER'
        );

        $registerA = $this->createRegister(
            $store,
            'CAISSE-01'
        );

        $registerB = $this->createRegister(
            $store,
            'CAISSE-02'
        );

        $first = $this->open(
            $store,
            $registerA,
            $owner
        );

        $second = $this->open(
            $store,
            $registerB,
            $manager
        );

        $this->assertSame('OPEN', $first->status);
        $this->assertSame('OPEN', $second->status);
        $this->assertDatabaseCount('pos_sessions', 2);
    }

    public function test_same_register_cannot_be_opened_twice(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $this->open($store, $register, $cashier);

        $this->assertDatabaseCount('pos_sessions', 1);

        $this->expectException(ValidationException::class);

        $this->open($store, $register, $cashier);
    }

    public function test_inactive_register_cannot_be_opened(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $register->status = 'INACTIVE';
        $register->save();

        $this->expectException(ValidationException::class);

        $this->open($store, $register, $cashier);
    }

    public function test_cross_store_register_is_inaccessible(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $registerB = $this->createRegister($storeB);
        $cashierA = $this->createActor($storeA);

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->open($storeA, $registerB, $cashierA);
    }

    public function test_delivery_agent_cannot_open_register(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $deliveryAgent = $this->createActor(
            $store,
            'DELIVERY_AGENT'
        );

        // Même une permission accordée par erreur
        // ne doit pas autoriser le livreur.
        $role = Role::where('code', 'DELIVERY_AGENT')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.sessions.open'
        )->firstOrFail();

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $this->expectException(
            AuthorizationException::class
        );

        $this->open($store, $register, $deliveryAgent);
    }

    public function test_user_without_permission_is_denied(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $role = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $permission = Permission::where(
            'code',
            'pos.sessions.open'
        )->firstOrFail();

        $role->permissions()->detach($permission->id);

        $this->expectException(
            AuthorizationException::class
        );

        $this->open($store, $register, $cashier);
    }

    public function test_invalid_opening_cash_is_rejected(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $this->expectException(
            ValidationException::class
        );

        $this->open(
            $store,
            $register,
            $cashier,
            '-5000.00'
        );
    }

    public function test_cannot_open_on_behalf_of_another_user(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);

        $owner = $this->createActor(
            $store,
            'STORE_OWNER'
        );

        $cashier = $this->createActor(
            $store,
            'CASHIER'
        );

        app(TenantContext::class)->setFromStore($store);

        // Le propriétaire est connecté, mais
        // tente d'attribuer l'ouverture à la caissière.
        $this->actingAs($owner);

        $this->expectException(
            AuthorizationException::class
        );

        app(OpenPosSession::class)->execute(
            $register->id,
            '5000.00',
            $cashier
        );
    }

    public function test_suspended_store_cannot_open_register(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createActor($store);

        $store->status = 'INACTIVE';
        $store->save();

        $this->expectException(
            ValidationException::class
        );

        $this->open($store, $register, $cashier);
    }
}
