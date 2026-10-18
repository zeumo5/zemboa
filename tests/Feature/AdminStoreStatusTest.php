<?php

namespace Tests\Feature;


use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminStoreStatusTest extends TestCase
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

        $this->seed(DatabaseSeeder::class);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'SUPER_ADMIN')
            ->firstOrFail();

        $admin->roles()->attach($role->id);

        return $admin;
    }

    private function createStore(
        string $status = 'PENDING'
    ): Store {
        return Store::create([
            'name' => 'Boutique Audit ZEMBOA',
            'slug' => 'audit-' . Str::lower(Str::random(12)),
            'status' => $status,
        ]);
    }

    private function createOwner(
        Store $store,
        string $status = 'ACTIVE',
        bool $verified = true
    ): User {
        $owner = User::factory()->create([
            'store_id' => $store->id,
            'status' => $status,
            'email_verified_at' => $verified ? now() : null,
        ]);

        $role = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $owner->roles()->attach($role->id);

        return $owner;
    }

    private function statusUrl(Store $store): string
    {
        return route('admin.stores.update-status', [
            'storeId' => $store->id,
        ]);
    }

private function patchStatus(
    Store $store,
    array $data
) {
    return $this->patchJson(
        $this->statusUrl($store),
        array_merge([
            'reason' =>
                'Décision administrative documentée pour cette boutique.',
        ], $data)
    );
}


    public function test_guest_cannot_manage_store(): void
    {
        $store = $this->createStore();

        $this->getJson(route('admin.stores.show', [
            'storeId' => $store->id,
        ]))->assertUnauthorized();

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
        ])->assertUnauthorized();

        $this->assertSame(
            'PENDING',
            $store->fresh()->status
        );
    }

    public function test_owner_cannot_manage_admin_store(): void
    {
        $store = $this->createStore();
        $owner = $this->createOwner($store);

        $this->actingAs($owner);

        $this->getJson(route('admin.stores.show', [
            'storeId' => $store->id,
        ]))->assertForbidden();

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
        ])->assertForbidden();
    }

    public function test_admin_can_view_store_details(): void
    {
        $store = $this->createStore();
        $owner = $this->createOwner($store);

        $this->actingAs($this->createAdmin());

        $this->getJson(route('admin.stores.show', [
            'storeId' => $store->id,
        ]))
            ->assertOk()
            ->assertJsonPath('store.id', $store->id)
            ->assertJsonPath('store.status', 'PENDING')
            ->assertJsonPath('owners.0.id', $owner->id);

        $this->getJson(route('admin.stores.show', [
            'storeId' => 999999,
        ]))->assertNotFound();
    }

    public function test_store_without_owner_cannot_be_activated(): void
    {
        $store = $this->createStore();

        $this->actingAs($this->createAdmin());

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(
            'PENDING',
            $store->fresh()->status
        );
    }

    public function test_invited_owner_blocks_activation(): void
    {
        $store = $this->createStore();

        $this->createOwner(
            $store,
            'INVITED',
            false
        );

        $this->actingAs($this->createAdmin());

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
        ])->assertUnprocessable();

        $this->assertSame(
            'PENDING',
            $store->fresh()->status
        );
    }

    public function test_multiple_owners_block_activation(): void
    {
        $store = $this->createStore();

        $this->createOwner($store);
        $this->createOwner($store);

        $this->actingAs($this->createAdmin());

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
        ])->assertUnprocessable();

        $this->assertSame(
            'PENDING',
            $store->fresh()->status
        );
    }

    public function test_mixed_owner_roles_block_activation(): void
    {
        $store = $this->createStore();
        $owner = $this->createOwner($store);

        $cashier = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $owner->roles()->attach($cashier->id);

        $this->actingAs($this->createAdmin());

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
        ])->assertUnprocessable();

        $this->assertSame(
            'PENDING',
            $store->fresh()->status
        );
    }

    public function test_admin_can_activate_and_suspend_store(): void
    {
        $store = $this->createStore();
        $owner = $this->createOwner($store);
        $admin = $this->createAdmin();

        $this->actingAs($admin);

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
        ])
            ->assertOk()
            ->assertJsonPath('store.status', 'ACTIVE');

        $this->actingAs($owner);

        $this->getJson(route('account.show'))
            ->assertOk();

        $this->actingAs($admin);

        $this->patchStatus($store, [
            'status' => 'INACTIVE',
        ])
            ->assertOk()
            ->assertJsonPath('store.status', 'INACTIVE');

        $this->actingAs($owner);

        // Une boutique suspendue ne peut plus
        // accéder à son espace professionnel.
        $this->getJson(route('account.show'))
            ->assertForbidden();

        $this->assertSame(
            'INACTIVE',
            $store->fresh()->status
        );
    }

    public function test_invalid_status_is_rejected(): void
    {
        $store = $this->createStore();
        $this->createOwner($store);

        $this->actingAs($this->createAdmin());

        foreach (['PENDING', 'DELETED', 'UNKNOWN'] as $status) {
            $this->patchStatus($store, [
                'status' => $status,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }

        $this->assertSame(
            'PENDING',
            $store->fresh()->status
        );
    }

    public function test_status_changes_are_audited(): void
    {
        $store = $this->createStore();
        $this->createOwner($store);
        $admin = $this->createAdmin();

        $this->actingAs($admin);

        $this->patchStatus($store, [
            'status' => 'ACTIVE',
            'reason' => 'Documents du commerçant vérifiés.',
        ])->assertOk();

        $this->patchStatus($store, [
            'status' => 'INACTIVE',
            'reason' => 'Suspension administrative justifiée.',
        ])->assertOk();

        $events = DB::table('store_status_events')
            ->where('store_id', $store->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);

        $this->assertSame('PENDING', $events[0]->old_status);
        $this->assertSame('ACTIVE', $events[0]->new_status);
        $this->assertEquals($admin->id, $events[0]->admin_user_id);

        $this->assertSame('ACTIVE', $events[1]->old_status);
        $this->assertSame('INACTIVE', $events[1]->new_status);
        $this->assertSame(
            'Suspension administrative justifiée.',
            $events[1]->reason
        );

        $this->getJson(route('admin.stores.status-events.index', [
            'storeId' => $store->id,
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_reason_is_required_for_status_change(): void
    {
        $store = $this->createStore();

        $this->actingAs($this->createAdmin());

        // Aucun motif.
        $this->patchJson($this->statusUrl($store), [
            'status' => 'INACTIVE',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        // Motif trop court.
        $this->patchStatus($store, [
            'status' => 'INACTIVE',
            'reason' => 'Court',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseCount('store_status_events', 0);
        $this->assertSame('PENDING', $store->fresh()->status);
    }

    public function test_identical_status_creates_no_event(): void
    {
        $store = $this->createStore('INACTIVE');

        $this->actingAs($this->createAdmin());

        $this->patchStatus($store, [
            'status' => 'INACTIVE',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount('store_status_events', 0);
    }

    public function test_only_admin_can_read_status_history(): void
    {
        $store = $this->createStore();

        $url = route('admin.stores.status-events.index', [
            'storeId' => $store->id,
        ]);

        // Visiteur non connecté.
        $this->getJson($url)->assertUnauthorized();

        // Propriétaire de boutique.
        $owner = $this->createOwner($store);

        $this->actingAs($owner);

        $this->getJson($url)->assertForbidden();

        // Super administrateur.
        $this->actingAs($this->createAdmin());

        $this->getJson($url)->assertOk();
    }
public function test_audit_failure_rolls_back_store_status(): void
{
    $store = $this->createStore('PENDING');
    $this->createOwner($store);

    $admin = $this->createAdmin();

    $this->actingAs($admin);

    // Simuler une panne d'écriture du journal
    // uniquement dans la base SQLite de test.
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER block_store_status_audit
        BEFORE INSERT ON store_status_events
        BEGIN
            SELECT RAISE(ABORT, 'audit_write_blocked');
        END;
    SQL);

    $this->withoutExceptionHandling();

    try {
        $this->patchStatus($store, [
            'status' => 'ACTIVE',
            'reason' => 'Validation administrative de la boutique.',
        ]);

        $this->fail(
            'Une erreur SQL était attendue.'
        );
    } catch (QueryException $exception) {
        $this->assertStringContainsString(
            'audit_write_blocked',
            $exception->getMessage()
        );
    }

    // Le changement doit être annulé.
    $this->assertSame(
        'PENDING',
        $store->fresh()->status
    );

    // Aucun événement ne doit être enregistré.
    $this->assertDatabaseCount(
        'store_status_events',
        0
    );
}


}
