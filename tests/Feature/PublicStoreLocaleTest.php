<?php
namespace Tests\Feature;


use App\Http\Middleware\ApplyStoreLocale;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class PublicStoreLocaleTest extends TestCase
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

        config()->set('app.locale', 'fr');

        // Route temporaire uniquement pour ces tests.
        // Elle utilise les vrais middlewares des boutiques.
        Route::middleware([
            'web',
            'public.tenant',
            ApplyStoreLocale::class,
        ])->get(
            '/__tests/shops/{shopSlug}/locale-preview',
            function () {
                return response()->json([
                    'locale' => app()->getLocale(),
                    'products' => __('ui.products'),
                    'orders' => __('ui.orders'),
                    'store_id' =>
                        app(TenantContext::class)->storeId(),
                ]);
            }
        );
    }

    private function createStore(
        string $slug,
        ?string $locale
    ): Store {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        if ($locale !== null) {
            app(TenantContext::class)
                ->setFromStore($store);

            StoreSetting::create([
                'default_locale' => $locale,
            ]);
        }

        return $store;
    }

    private function previewUrl(Store $store): string
    {
        return '/__tests/shops/'
            . $store->slug
            . '/locale-preview';
    }

    public function test_guest_uses_english_store_language(): void
    {
        $store = $this->createStore('beta', 'en');

        $this->getJson($this->previewUrl($store))
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('products', 'Products')
            ->assertJsonPath('orders', 'Orders')
            ->assertJsonPath('store_id', $store->id);
    }

    public function test_guest_uses_french_store_language(): void
    {
        $store = $this->createStore('alpha', 'fr');

        $this->getJson($this->previewUrl($store))
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath('products', 'Produits')
            ->assertJsonPath('orders', 'Commandes');
    }

    public function test_store_without_settings_uses_french(): void
    {
        $store = $this->createStore('gamma', null);

        $this->getJson($this->previewUrl($store))
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath('products', 'Produits');
    }

    public function test_guest_preference_overrides_store_language(): void
    {
        $store = $this->createStore('beta', 'en');

        // La boutique préfère l'anglais.
        // Le visiteur préfère le français.
        $this->withSession([
            'locale' => 'fr',
        ]);

        $this->getJson($this->previewUrl($store))
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath('products', 'Produits');
    }

    public function test_user_preference_overrides_store_and_session(): void
    {
        $store = $this->createStore('alpha', 'fr');

        $user = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
        ]);

        $user->preferred_locale = 'en';
        $user->save();

        $this->actingAs($user);

        $this->withSession([
            'locale' => 'fr',
        ]);

        // L'anglais personnel reste prioritaire.
        $this->getJson($this->previewUrl($store))
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('products', 'Products');

        // La visite ne doit pas modifier
        // la préférence enregistrée.
        $this->assertSame(
            'en',
            $user->fresh()->preferred_locale
        );
    }

    public function test_switching_stores_does_not_mix_languages(): void
    {
        $storeA = $this->createStore('alpha', 'fr');
        $storeB = $this->createStore('beta', 'en');

        // Première boutique : français.
        $this->getJson($this->previewUrl($storeA))
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath('store_id', $storeA->id);

        // Deuxième boutique : anglais.
        $this->getJson($this->previewUrl($storeB))
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('products', 'Products')
            ->assertJsonPath('store_id', $storeB->id);

        // Retour à la première boutique.
        $this->getJson($this->previewUrl($storeA))
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath('products', 'Produits')
            ->assertJsonPath('store_id', $storeA->id);
    }

    public function test_inactive_store_is_not_accessible(): void
    {
        $store = $this->createStore('inactive', 'en');

        $store->status = 'INACTIVE';
        $store->save();

        $this->getJson(
            $this->previewUrl($store)
        )->assertNotFound();
    }
}
