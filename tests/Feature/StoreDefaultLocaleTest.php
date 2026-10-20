<?php
namespace Tests\Feature;


use App\Models\Store;
use App\Models\StoreSetting;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StoreDefaultLocaleTest extends TestCase
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

    private function createStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function selectStore(Store $store): void
    {
        app(TenantContext::class)->setFromStore($store);
    }

    public function test_store_locale_defaults_to_french(): void
    {
        $store = $this->createStore('alpha');
        $this->selectStore($store);

        $this->assertTrue(
            Schema::hasColumn(
                'store_settings',
                'default_locale'
            )
        );

        $settings = StoreSetting::create([])
            ->refresh();

        $this->assertSame(
            'fr',
            $settings->default_locale
        );

        $this->assertSame(
            $store->id,
            $settings->store_id
        );
    }

    public function test_store_can_choose_english_or_french(): void
    {
        $store = $this->createStore('alpha');
        $this->selectStore($store);

        $settings = StoreSetting::create([
            'default_locale' => 'en',
        ])->refresh();

        $this->assertSame(
            'en',
            $settings->default_locale
        );

        $settings->update([
            'default_locale' => 'fr',
        ]);

        $this->assertSame(
            'fr',
            $settings->fresh()->default_locale
        );
    }

    public function test_store_languages_are_isolated(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $this->selectStore($storeA);

        $settingsA = StoreSetting::create([
            'default_locale' => 'fr',
        ]);

        $this->selectStore($storeB);

        $settingsB = StoreSetting::create([
            'default_locale' => 'en',
        ]);

        // La boutique B ne voit que ses paramètres.
        $this->assertSame(
            'en',
            StoreSetting::firstOrFail()
                ->default_locale
        );

        $this->assertNull(
            StoreSetting::find($settingsA->id)
        );

        // Revenir à la boutique A.
        $this->selectStore($storeA);

        $this->assertSame(
            'fr',
            StoreSetting::firstOrFail()
                ->default_locale
        );

        $this->assertNull(
            StoreSetting::find($settingsB->id)
        );

        // Les deux enregistrements existent bien,
        // mais restent isolés par boutique.
        $this->assertSame(
            2,
            DB::table('store_settings')->count()
        );
    }

    public function test_store_id_cannot_be_mass_assigned(): void
    {
        $settings = new StoreSetting();

        $this->assertFalse(
            $settings->isFillable('store_id')
        );

        $this->assertTrue(
            $settings->isFillable('default_locale')
        );
    }
}
