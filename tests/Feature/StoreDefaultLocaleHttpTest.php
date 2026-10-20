<?php
namespace Tests\Feature;


use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StoreDefaultLocaleHttpTest extends TestCase
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

        // Uniquement en SQLite :memory:.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function createStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function createActor(
        Store $store,
        string $roleCode = 'STORE_OWNER'
    ): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_owner_can_choose_english_and_french(): void
    {
        $store = $this->createStore('alpha');
        $owner = $this->createActor($store);

        $this->actingAs($owner);

        // Choisir l'anglais.
        $this->patchJson('/store/settings', [
            'default_locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'en');

        $this->assertDatabaseHas('store_settings', [
            'store_id' => $store->id,
            'default_locale' => 'en',
        ]);

        // Revenir au français.
        $this->patchJson('/store/settings', [
            'default_locale' => 'fr',
        ])
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'fr');

        $this->getJson('/store/settings')
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'fr');

        $this->assertDatabaseCount('store_settings', 1);
    }

    public function test_invalid_store_languages_are_rejected(): void
    {
        $store = $this->createStore('alpha');
        $owner = $this->createActor($store);

        $this->actingAs($owner);

        foreach (['es', 'FR', ''] as $locale) {
            $this->patchJson('/store/settings', [
                'default_locale' => $locale,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'default_locale',
                ]);
        }

        $this->assertDatabaseCount('store_settings', 0);
    }

    public function test_store_languages_remain_isolated(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $ownerA = $this->createActor($storeA);
        $ownerB = $this->createActor($storeB);

        $this->actingAs($ownerA);

        $this->patchJson('/store/settings', [
            'default_locale' => 'en',
        ])->assertOk();

        $this->actingAs($ownerB);

        $this->patchJson('/store/settings', [
            'default_locale' => 'fr',
        ])->assertOk();

        $this->assertDatabaseHas('store_settings', [
            'store_id' => $storeA->id,
            'default_locale' => 'en',
        ]);

        $this->assertDatabaseHas('store_settings', [
            'store_id' => $storeB->id,
            'default_locale' => 'fr',
        ]);

        // Le propriétaire A retrouve ses paramètres.
        $this->actingAs($ownerA);

        $this->getJson('/store/settings')
            ->assertOk()
            ->assertJsonPath('data.default_locale', 'en');

        $this->assertDatabaseCount('store_settings', 2);
    }

    public function test_cashier_cannot_change_store_language(): void
    {
        $store = $this->createStore('alpha');

        $cashier = $this->createActor(
            $store,
            'CASHIER'
        );

        $this->actingAs($cashier);

        $this->patchJson('/store/settings', [
            'default_locale' => 'en',
        ])->assertForbidden();

        $this->assertDatabaseCount('store_settings', 0);
    }
}
