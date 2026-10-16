<?php
namespace Tests\Feature;


use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class TenantMiddlewareAuditTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    // Protection impérative de la vraie base MySQL.
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

        // Routes réservées aux tests :
        // aucune modification des routes ZEMBOA.
        Route::middleware(['web', 'auth', 'tenant'])
            ->get('/__audit/tenant', function () {
                return response()->json([
                    'store_id' => app(TenantContext::class)->storeId(),
                ]);
            });

        Route::middleware(['web', 'public.tenant'])
            ->get('/__audit/public/{shopSlug}', function () {
                return response()->json([
                    'store_id' => app(TenantContext::class)->storeId(),
                ]);
            });
    }

    private function createAuditStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    // 1. Les visiteurs ne peuvent pas accéder aux routes privées.
    public function test_guest_cannot_access_private_tenant(): void
    {
        $this->getJson('/__audit/tenant')
            ->assertUnauthorized();
    }

    // 2. Une boutique active peut accéder à ses propres données.
    public function test_active_store_can_access_private_tenant(): void
    {
        $store = $this->createAuditStore('audit-active');

        $user = User::factory()->create([
    'store_id' => $store->id,
    'status' => 'ACTIVE',
]);

        $this->actingAs($user);

        $this->getJson('/__audit/tenant')
            ->assertOk()
            ->assertJsonPath('store_id', $store->id);
    }

    // 3. Un utilisateur sans boutique doit être refusé.
    public function test_user_without_store_is_denied(): void
    {
        $user = User::factory()->create([
            'store_id' => null,
        ]);

        $this->actingAs($user);

        $this->getJson('/__audit/tenant')
            ->assertForbidden();
    }

    // 4. Une boutique inactive ne doit pas accéder au backend.
    public function test_inactive_store_is_denied(): void
    {
        $store = $this->createAuditStore('audit-inactive');

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $store->status = 'INACTIVE';
        $store->save();

        $this->actingAs($user);

        $this->getJson('/__audit/tenant')
            ->assertForbidden();
    }

    // 5. Changer d'utilisateur doit changer de boutique.
    public function test_tenant_context_does_not_leak_between_requests(): void
    {
        $storeA = $this->createAuditStore('audit-a');
        $storeB = $this->createAuditStore('audit-b');

        $userA = User::factory()->create([
    'store_id' => $storeA->id,
    'status' => 'ACTIVE',
]);

$userB = User::factory()->create([
    'store_id' => $storeB->id,
    'status' => 'ACTIVE',
]);

        $this->actingAs($userA);

        $this->getJson('/__audit/tenant')
            ->assertOk()
            ->assertJsonPath('store_id', $storeA->id);

        $this->actingAs($userB);

        $this->getJson('/__audit/tenant')
            ->assertOk()
            ->assertJsonPath('store_id', $storeB->id);

        $this->assertNotEquals($storeA->id, $storeB->id);
    }

    // 6. Seules les boutiques actives sont visibles publiquement.
    public function test_public_catalog_rejects_inactive_store(): void
    {
        $active = $this->createAuditStore('audit-public-active');
        $inactive = $this->createAuditStore('audit-public-inactive');

        $inactive->status = 'INACTIVE';
        $inactive->save();

        $this->getJson('/__audit/public/' . $active->slug)
            ->assertOk()
            ->assertJsonPath('store_id', $active->id);

        $this->getJson('/__audit/public/' . $inactive->slug)
            ->assertNotFound();
    }


public function test_inactive_user_cannot_access_active_store(): void
{
    $store = $this->createAuditStore(
        'audit-inactive-user'
    );

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    // Désactiver uniquement le compte utilisateur.
    $user->status = 'INACTIVE';
    $user->save();

    $this->actingAs($user);

    $this->getJson('/__audit/tenant')
        ->assertForbidden();

    // La boutique reste active.
    $this->assertDatabaseHas('stores', [
        'id' => $store->id,
        'status' => 'ACTIVE',
    ]);
}

public function test_reactivated_user_can_access_active_store(): void
{
    $store = $this->createAuditStore(
        'audit-reactivated-user'
    );

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    // Désactiver temporairement le compte.
    $user->status = 'INACTIVE';
    $user->save();

    $this->actingAs($user);

    $this->getJson('/__audit/tenant')
        ->assertForbidden();

    // Réactiver le compte.
    $user->status = 'ACTIVE';
    $user->save();

    $this->getJson('/__audit/tenant')
        ->assertOk()
        ->assertJsonPath('store_id', $store->id);
}

}
