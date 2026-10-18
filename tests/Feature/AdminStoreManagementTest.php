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

class AdminStoreManagementTest extends TestCase
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

    private function storeData(): array
    {
        return [
            'name' => 'Boutique ZEMBOA Test',
            'slug' => 'boutique-' . Str::lower(Str::random(10)),
            'description' => 'Boutique de test',
            'phone' => '690000000',
            'email' => 'boutique@example.test',
            'address' => 'Douala, Cameroun',
            'owner_name' => 'Propriétaire Test',
            'owner_email' => 'owner-' . Str::lower(Str::random(10))
                . '@example.test',
        ];
    }

    public function test_guest_cannot_create_store(): void
    {
        $this->postJson(
            route('admin.stores.store'),
            $this->storeData()
        )->assertUnauthorized();

        $this->assertDatabaseCount('stores', 0);
    }

    public function test_store_owner_cannot_create_store(): void
    {
        $store = Store::create([
            'name' => 'Boutique Existante',
            'slug' => 'boutique-existante',
            'status' => 'ACTIVE',
        ]);

        $owner = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $owner->roles()->attach($role->id);

        $this->actingAs($owner);

        $this->postJson(
            route('admin.stores.store'),
            $this->storeData()
        )->assertForbidden();

        $this->assertDatabaseCount('stores', 1);
    }

    public function test_admin_can_create_store_with_owner(): void
    {
        $this->actingAs($this->createAdmin());

        $data = $this->storeData();

        $this->postJson(
            route('admin.stores.store'),
            $data
        )
            ->assertCreated()
            ->assertJsonPath('store.status', 'PENDING')
            ->assertJsonPath('owner.status', 'INVITED')
            ->assertJsonPath('owner.role', 'STORE_OWNER');

        $store = Store::where('slug', $data['slug'])
            ->firstOrFail();

        $owner = User::where('email', $data['owner_email'])
            ->firstOrFail();

        $this->assertSame($store->id, $owner->store_id);

        $this->assertTrue(
            $owner->hasRole('STORE_OWNER')
        );

        $this->assertFalse(
            $owner->hasRole('SUPER_ADMIN')
        );

        $this->assertNull($owner->email_verified_at);
    }

    public function test_admin_can_list_stores(): void
    {
        $this->actingAs($this->createAdmin());

        Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'PENDING',
        ]);

        $this->getJson(route('admin.stores.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $this->actingAs($this->createAdmin());

        $data = $this->storeData();

        $this->postJson(
            route('admin.stores.store'),
            $data
        )->assertCreated();

        $second = $this->storeData();
        $second['slug'] = $data['slug'];

        $this->postJson(
            route('admin.stores.store'),
            $second
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->assertDatabaseCount('stores', 1);
    }

    public function test_client_cannot_force_active_store_or_admin_role(): void
    {
        $this->actingAs($this->createAdmin());

        $data = array_merge($this->storeData(), [
            'status' => 'ACTIVE',
            'role' => 'SUPER_ADMIN',
            'store_id' => 999,
        ]);

        $this->postJson(
            route('admin.stores.store'),
            $data
        )
            ->assertCreated()
            ->assertJsonPath('store.status', 'PENDING')
            ->assertJsonPath('owner.status', 'INVITED')
            ->assertJsonPath('owner.role', 'STORE_OWNER');

        $owner = User::where('email', $data['owner_email'])
            ->firstOrFail();

        $this->assertFalse(
            $owner->hasRole('SUPER_ADMIN')
        );

        $this->assertNotSame(999, $owner->store_id);
    }
}
