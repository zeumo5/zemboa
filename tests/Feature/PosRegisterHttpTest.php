<?php

namespace Tests\Feature;

use App\Actions\Pos\OpenPosSession;
use App\Actions\Pos\ClosePosSession;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use App\Models\Permission;
use App\Models\PosRegister;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PosRegisterHttpTest extends TestCase
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

        // Base de test isolée SQLite :memory:.
        $this->seed(DatabaseSeeder::class);

        // S'assurer que les nouvelles permissions
        // POS existent dans la base de test.
        $this->seed(PermissionSeeder::class);

        // Attribuer les permissions aux rôles.
        // sync() est autorisé ici uniquement
        // parce que la base SQLite est jetable.
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

    private function createUser(Store $store, string $roleCode): User
    {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
        ]);

        $role = Role::query()
            ->where('code', $roleCode)
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
            'name' => 'Caisse de test',
            'code' => $code,
        ])->refresh();
    }

    private function updateUrl(PosRegister $register): string
    {
        return route('pos.registers.update', [
            'registerId' => $register->id,
        ]);
    }

    private function statusUrl(PosRegister $register): string
    {
        return route('pos.registers.update-status', [
            'registerId' => $register->id,
        ]);
    }

    public function test_guest_cannot_access_register_routes(): void
    {
        $this->getJson(route('pos.registers.index'))
            ->assertUnauthorized();

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Caisse',
            'code' => 'CAISSE-01',
        ])->assertUnauthorized();

        $this->patchJson(route('pos.registers.update', [
            'registerId' => 1,
        ]), [
            'name' => 'Autre caisse',
        ])->assertUnauthorized();

        $this->patchJson(route('pos.registers.update-status', [
            'registerId' => 1,
        ]), [
            'status' => 'INACTIVE',
        ])->assertUnauthorized();
    }

    public function test_owner_can_create_and_list_registers(): void
    {
        $store = $this->createStore();
        $owner = $this->createUser($store, 'STORE_OWNER');

        $this->actingAs($owner);

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ])
            ->assertCreated()
            ->assertJsonPath('register.code', 'CAISSE-01')
            ->assertJsonPath('register.status', 'ACTIVE')
            ->assertJsonPath('register.store_id', $store->id);

        $this->getJson(route('pos.registers.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'CAISSE-01');
    }

    public function test_manager_can_manage_registers(): void
    {
        $store = $this->createStore();
        $manager = $this->createUser(
            $store,
            'STORE_MANAGER'
        );

        $this->actingAs($manager);

        $response = $this->postJson(
            route('pos.registers.store'),
            [
                'name' => 'Caisse principale',
                'code' => 'CAISSE-01',
            ]
        )->assertCreated();

        $registerId = $response->json('register.id');

        $this->patchJson(route('pos.registers.update', [
            'registerId' => $registerId,
        ]), [
            'name' => 'Caisse secondaire',
            'code' => 'CAISSE-02',
        ])
            ->assertOk()
            ->assertJsonPath('register.code', 'CAISSE-02');

        $this->patchJson(route('pos.registers.update-status', [
            'registerId' => $registerId,
        ]), [
            'status' => 'INACTIVE',
        ])
            ->assertOk()
            ->assertJsonPath('register.status', 'INACTIVE');

        $this->assertDatabaseHas('pos_registers', [
            'id' => $registerId,
            'store_id' => $store->id,
            'code' => 'CAISSE-02',
            'status' => 'INACTIVE',
        ]);
    }

    public function test_cashier_can_view_but_cannot_manage_registers(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $cashier = $this->createUser($store, 'CASHIER');

        $this->actingAs($cashier);

        $this->getJson(route('pos.registers.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Caisse interdite',
            'code' => 'CAISSE-02',
        ])->assertForbidden();

        $this->patchJson($this->updateUrl($register), [
            'name' => 'Modification interdite',
        ])->assertForbidden();

        $this->patchJson($this->statusUrl($register), [
            'status' => 'INACTIVE',
        ])->assertForbidden();

        $this->assertDatabaseHas('pos_registers', [
            'id' => $register->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_delivery_agent_cannot_access_registers(): void
    {
        $store = $this->createStore();
        $register = $this->createRegister($store);
        $deliveryAgent = $this->createUser(
            $store,
            'DELIVERY_AGENT'
        );

        // Même une permission attribuée par erreur
        // ne doit pas autoriser un livreur.
        $permission = Permission::query()
            ->where('code', 'pos.registers.view')
            ->firstOrFail();

        $role = Role::query()
            ->where('code', 'DELIVERY_AGENT')
            ->firstOrFail();

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $this->actingAs($deliveryAgent);

        $this->getJson(route('pos.registers.index'))
            ->assertForbidden();

        $this->patchJson($this->updateUrl($register), [
            'name' => 'Modification interdite',
        ])->assertForbidden();
    }

    public function test_registers_from_another_store_are_inaccessible(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $ownerA = $this->createUser(
            $storeA,
            'STORE_OWNER'
        );

        $registerB = $this->createRegister(
            $storeB,
            'CAISSE-B'
        );

        $this->actingAs($ownerA);

        $this->getJson(route('pos.registers.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->patchJson($this->updateUrl($registerB), [
            'name' => 'Tentative de modification',
        ])->assertNotFound();

        $this->patchJson($this->statusUrl($registerB), [
            'status' => 'INACTIVE',
        ])->assertNotFound();

        $this->assertDatabaseHas('pos_registers', [
            'id' => $registerB->id,
            'store_id' => $storeB->id,
            'status' => 'ACTIVE',
            'name' => 'Caisse de test',
        ]);
    }

    public function test_register_code_is_unique_per_store(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $ownerA = $this->createUser(
            $storeA,
            'STORE_OWNER'
        );

        $ownerB = $this->createUser(
            $storeB,
            'STORE_OWNER'
        );

        $this->actingAs($ownerA);

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Première caisse',
            'code' => 'CAISSE-01',
        ])->assertCreated();

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Doublon',
            'code' => 'CAISSE-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        // Même code autorisé dans une autre boutique.
        $this->actingAs($ownerB);

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Caisse Beta',
            'code' => 'CAISSE-01',
        ])->assertCreated();

        $this->assertDatabaseCount('pos_registers', 2);
    }

    public function test_request_cannot_override_store_or_status(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $ownerA = $this->createUser(
            $storeA,
            'STORE_OWNER'
        );

        $this->actingAs($ownerA);

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Caisse protégée',
            'code' => 'CAISSE-01',
            'store_id' => $storeB->id,
            'status' => 'INACTIVE',
        ])
            ->assertCreated()
            ->assertJsonPath('register.store_id', $storeA->id)
            ->assertJsonPath('register.status', 'ACTIVE');
    }

    public function test_invalid_register_data_is_rejected(): void
    {
        $store = $this->createStore();
        $owner = $this->createUser($store, 'STORE_OWNER');

        $this->actingAs($owner);

        $this->postJson(route('pos.registers.store'), [
            'name' => '',
            'code' => 'caisse 01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'code',
            ]);

        $register = $this->createRegister($store);

        $this->patchJson($this->statusUrl($register), [
            'status' => 'SUSPENDED',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('pos_registers', [
            'id' => $register->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_owner_without_manage_permission_is_denied(): void
    {
        $store = $this->createStore();
        $owner = $this->createUser($store, 'STORE_OWNER');

        $role = Role::query()
            ->where('code', 'STORE_OWNER')
            ->firstOrFail();

        $permission = Permission::query()
            ->where('code', 'pos.registers.manage')
            ->firstOrFail();

        $role->permissions()->detach($permission->id);

        $this->actingAs($owner);

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Caisse interdite',
            'code' => 'CAISSE-01',
        ])->assertForbidden();

        $this->assertDatabaseCount('pos_registers', 0);
    }

    public function test_suspended_store_cannot_use_pos(): void
    {
        $store = $this->createStore();
        $owner = $this->createUser($store, 'STORE_OWNER');

        $store->status = 'INACTIVE';
        $store->save();

        $this->actingAs($owner);

        $this->getJson(route('pos.registers.index'))
            ->assertForbidden();

        $this->postJson(route('pos.registers.store'), [
            'name' => 'Caisse interdite',
            'code' => 'CAISSE-01',
        ])->assertForbidden();

        $this->assertDatabaseCount('pos_registers', 0);
    }

    
public function test_register_cannot_be_disabled_until_session_is_closed(): void
{
    $store = $this->createStore();

    $owner = $this->createUser($store, 'STORE_OWNER');
    $cashier = $this->createUser($store, 'CASHIER');

    $register = $this->createRegister($store);

    // 1. La caissière ouvre sa session.
    $this->actingAs($cashier);

    app(TenantContext::class)->setFromStore($store);

    $session = app(OpenPosSession::class)->execute(
        $register->id,
        '5000.00',
        $cashier
    );

    $this->assertSame('OPEN', $session->status);

    // 2. Le propriétaire tente de désactiver
    // la caisse pendant que la session est ouverte.
    $this->actingAs($owner);

    $this->patchJson($this->statusUrl($register), [
        'status' => 'INACTIVE',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    // La caisse reste active.
    $this->assertSame(
        'ACTIVE',
        $register->fresh()->status
    );

    // 3. La caissière clôture sa session.
    $this->actingAs($cashier);

    app(TenantContext::class)->setFromStore($store);

    app(ClosePosSession::class)->execute(
        $session->id,
        '5000.00',
        $cashier
    );

    $this->assertSame(
        'CLOSED',
        $session->fresh()->status
    );

    // 4. Le propriétaire désactive la caisse.
    $this->actingAs($owner);

    $this->patchJson($this->statusUrl($register), [
        'status' => 'INACTIVE',
    ])
        ->assertOk()
        ->assertJsonPath('register.status', 'INACTIVE');

    $this->assertSame(
        'INACTIVE',
        $register->fresh()->status
    );
}

}
