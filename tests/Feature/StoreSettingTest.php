<?php
namespace Tests\Feature;


use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreSettingTest extends TestCase
{
    use RefreshDatabase;

    // Protection AVANT toute migration de test.
    protected function beforeRefreshingDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'SECURITE ZEMBOA : tests autorises uniquement sur SQLite :memory:'
            );
        }
    }

    private function createStore(string $name): Store
    {
        return Store::create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'status' => 'ACTIVE',
        ]);
    }

    private function initializeTenant(Store $store): void
    {
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);
    }

    public function test_store_can_create_settings(): void
    {
        $store = $this->createStore('Boutique Alpha');
        $this->initializeTenant($store);

        $settings = StoreSetting::create([
            'primary_color' => '#2563EB',
        ]);

        $this->assertSame($store->id, $settings->store_id);
        $this->assertTrue($settings->store()->is($store));
        $this->assertTrue($store->settings()->exists());
    }

    public function test_store_id_is_assigned_automatically(): void
    {
        $store = $this->createStore('Boutique Alpha');
        $this->initializeTenant($store);

        $settings = StoreSetting::create([]);

        $this->assertSame($store->id, $settings->store_id);
    }

    public function test_store_cannot_force_another_store_id(): void
    {
        $storeA = $this->createStore('Boutique Alpha');
        $storeB = $this->createStore('Boutique Beta');

        $this->initializeTenant($storeA);

        $settings = StoreSetting::create([
            'store_id' => $storeB->id,
            'allow_reservations' => false,
        ]);

        $this->assertSame($storeA->id, $settings->store_id);
        $this->assertNotSame($storeB->id, $settings->store_id);
    }

    public function test_store_can_have_only_one_settings_record(): void
    {
        $store = $this->createStore('Boutique Alpha');
        $this->initializeTenant($store);

        StoreSetting::create([]);

        $this->expectException(QueryException::class);

        StoreSetting::create([]);
    }

    public function test_store_cannot_access_another_store_settings(): void
    {
        $storeA = $this->createStore('Boutique Alpha');
        $storeB = $this->createStore('Boutique Beta');

        $this->initializeTenant($storeA);

        $ownSettings = StoreSetting::create([
            'return_window_days' => 7,
        ]);

        // Création d'une donnée de test appartenant à B.
        DB::table('store_settings')->insert([
            'store_id' => $storeB->id,
            'return_window_days' => 14,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $visibleSettings = StoreSetting::all();

        $this->assertCount(1, $visibleSettings);
        $this->assertSame(
            $ownSettings->id,
            $visibleSettings->first()->id
        );

        // Une mise à jour de B par A doit être impossible
        // à travers la requête Eloquent scopée.
        $updated = StoreSetting::query()
            ->where('store_id', $storeB->id)
            ->update(['return_window_days' => 30]);

        $this->assertSame(0, $updated);

        $this->assertSame(
            14,
            DB::table('store_settings')
                ->where('store_id', $storeB->id)
                ->value('return_window_days')
        );
    }

    public function test_settings_defaults_and_casts_work(): void
    {
        $store = $this->createStore('Boutique Alpha');
        $this->initializeTenant($store);

        $hours = [
            'monday' => [
                'open' => '08:00',
                'close' => '18:00',
            ],
        ];

        $settings = StoreSetting::create([
            'opening_hours' => $hours,
            'delivery_enabled' => false,
        ]);

        $settings = $settings->fresh();

        $this->assertFalse($settings->delivery_enabled);
        $this->assertTrue($settings->allow_reservations);
        $this->assertSame(
            1440,
            $settings->reservation_duration_minutes
        );
        $this->assertSame(
            0,
            $settings->minimum_deposit_percent
        );
        $this->assertSame($hours, $settings->opening_hours);
    }
}
