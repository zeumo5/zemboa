<?php
namespace Tests\Feature;


use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSettingsRbacTest extends TestCase
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

    public function test_store_owner_receives_settings_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $permissions = $owner->permissions()
            ->pluck('code')
            ->all();

        $this->assertContains('settings.view', $permissions);
        $this->assertContains('settings.update', $permissions);

        // Les anciennes permissions restent présentes.
        $this->assertContains('products.create', $permissions);
        $this->assertContains('payments.confirm', $permissions);
    }

    public function test_delivery_agent_has_no_settings_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $deliveryAgent = Role::where('code', 'DELIVERY_AGENT')
            ->firstOrFail();

        $permissions = $deliveryAgent->permissions()
            ->pluck('code')
            ->all();

        $this->assertNotContains('settings.view', $permissions);
        $this->assertNotContains('settings.update', $permissions);

        $this->assertContains('deliveries.view', $permissions);
    }

    public function test_reseeding_does_not_duplicate_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $owner = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $count = $owner->permissions()
            ->whereIn('code', [
                'settings.view',
                'settings.update',
            ])
            ->count();

        $this->assertSame(2, $count);
    }
}
