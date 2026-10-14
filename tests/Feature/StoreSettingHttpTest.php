<?php
namespace Tests\Feature;


use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreSettingHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'SECURITE ZEMBOA : SQLite :memory: obligatoire.'
            );
        }
    }

    private function createUserWithRole(
        string $slug,
        string $roleCode
    ): User {
        $store = Store::create([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_guest_cannot_access_settings(): void
    {
        $this->getJson('/store/settings')
            ->assertUnauthorized();

        $this->patchJson('/store/settings', [
            'primary_color' => '#123456',
        ])->assertUnauthorized();
    }

    public function test_owner_can_view_settings(): void
    {
        $owner = $this->createUserWithRole(
            'boutique-alpha',
            'STORE_OWNER'
        );

        $this->actingAs($owner)
            ->getJson('/store/settings')
            ->assertOk()
            ->assertJsonPath('configured', false);
    }

    public function test_owner_can_create_and_update_settings(): void
    {
        $owner = $this->createUserWithRole(
            'boutique-alpha',
            'STORE_OWNER'
        );

        $this->actingAs($owner)
            ->patchJson('/store/settings', [
                'primary_color' => '#123456',
                'return_window_days' => 14,
            ])
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#123456');

        $this->assertDatabaseHas('store_settings', [
            'store_id' => $owner->store_id,
            'return_window_days' => 14,
        ]);

        $this->actingAs($owner)
            ->patchJson('/store/settings', [
                'return_window_days' => 10,
            ])
            ->assertOk();

        $this->assertDatabaseHas('store_settings', [
            'store_id' => $owner->store_id,
            'return_window_days' => 10,
        ]);

        $this->assertDatabaseCount('store_settings', 1);
    }

    public function test_delivery_agent_cannot_manage_settings(): void
    {
        $deliveryAgent = $this->createUserWithRole(
            'boutique-alpha',
            'DELIVERY_AGENT'
        );

        $this->actingAs($deliveryAgent)
            ->getJson('/store/settings')
            ->assertForbidden();

        $this->actingAs($deliveryAgent)
            ->patchJson('/store/settings', [
                'primary_color' => '#123456',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('store_settings', 0);
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $owner = $this->createUserWithRole(
            'boutique-alpha',
            'STORE_OWNER'
        );

        $this->actingAs($owner)
            ->patchJson('/store/settings', [
                'minimum_deposit_percent' => 150,
                'primary_color' => 'invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'minimum_deposit_percent',
                'primary_color',
            ]);

        $this->assertDatabaseCount('store_settings', 0);
    }

    public function test_settings_remain_isolated_between_stores(): void
    {
        $ownerA = $this->createUserWithRole(
            'boutique-alpha',
            'STORE_OWNER'
        );

        $ownerB = $this->createUserWithRole(
            'boutique-beta',
            'STORE_OWNER'
        );

        $this->actingAs($ownerB)
            ->patchJson('/store/settings', [
                'primary_color' => '#222222',
            ])
            ->assertOk();

        $this->actingAs($ownerA)
            ->patchJson('/store/settings', [
                'primary_color' => '#111111',
            ])
            ->assertOk();

        $this->assertSame(
            '#222222',
            DB::table('store_settings')
                ->where('store_id', $ownerB->store_id)
                ->value('primary_color')
        );

        $this->actingAs($ownerA)
            ->getJson('/store/settings')
            ->assertOk()
            ->assertJsonPath(
                'data.primary_color',
                '#111111'
            );

        $this->assertDatabaseCount('store_settings', 2);
    }
}
