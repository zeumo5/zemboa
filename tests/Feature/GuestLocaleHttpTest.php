<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class GuestLocaleHttpTest extends TestCase
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

        // Route temporaire uniquement pour les tests.
        Route::middleware('web')->get(
            '/__tests/guest-locale-preview',
            function () {
                return response()->json([
                    'locale' => app()->getLocale(),
                    'dashboard' => __('ui.dashboard'),
                    'cart' => session('cart'),
                    'checkout_note' => session('checkout_note'),
                ]);
            }
        );
    }

    private function url(): string
    {
        return route('guest.locale.update');
    }

    private function previewUrl(): string
    {
        return '/__tests/guest-locale-preview';
    }

    public function test_guest_can_select_english(): void
    {
        $this->postJson($this->url(), [
            'locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath(
                'message',
                'Language updated successfully.'
            );

        // Une nouvelle requête doit rester en anglais.
        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('dashboard', 'Dashboard');
    }

    public function test_guest_can_switch_back_to_french(): void
    {
        $this->withSession([
            'locale' => 'en',
        ]);

        $this->postJson($this->url(), [
            'locale' => 'fr',
        ])
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath(
                'message',
                'Langue mise à jour avec succès.'
            );

        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath(
                'dashboard',
                'Tableau de bord'
            );
    }

    public function test_invalid_languages_are_rejected(): void
    {
        $this->withSession([
            'locale' => 'en',
        ]);

        foreach (['es', 'de', 'FR', ''] as $locale) {
            $this->postJson($this->url(), [
                'locale' => $locale,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['locale']);
        }

        // Le champ est obligatoire.
        $this->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['locale']);

        // Les demandes invalides ne doivent pas
        // modifier la langue précédemment choisie.
        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en');
    }

    public function test_authenticated_user_cannot_use_guest_route(): void
    {
        $user = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
        ]);

        $user->preferred_locale = 'fr';
        $user->save();

        $this->actingAs($user);

        $this->postJson($this->url(), [
            'locale' => 'en',
        ])->assertForbidden();

        // Sa préférence personnelle reste intacte.
        $this->assertSame(
            'fr',
            $user->fresh()->preferred_locale
        );
    }

    public function test_changing_locale_preserves_cart_and_session(): void
    {
        $cart = [
            'items' => [
                [
                    'product_id' => 12,
                    'quantity' => 2,
                ],
            ],
        ];

        $this->withSession([
            'locale' => 'fr',
            'cart' => $cart,
            'checkout_note' => 'Livraison à Douala',
        ]);

        $this->postJson($this->url(), [
            'locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        // Le changement de langue ne doit pas
        // vider le panier du client.
        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('cart', $cart)
            ->assertJsonPath(
                'checkout_note',
                'Livraison à Douala'
            );
    }

    public function test_guest_locale_route_is_global(): void
    {
        $route = Route::getRoutes()->getByName(
            'guest.locale.update'
        );

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('web', $middleware);

        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('tenant', $middleware);

        $this->assertNotContains(
            'public.tenant',
            $middleware
        );

        $this->assertSame(
            'locale',
            $route->uri()
        );
    }

    public function test_validation_errors_are_displayed_in_french(): void
    {
        // Visiteur utilisant le français.
        $this->withSession([
            'locale' => 'fr',
        ]);

        // Envoyer une requête sans le champ obligatoire.
        $this->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.locale.0',
                'Le champ langue est obligatoire.'
            );

        // Vérifier également une langue non autorisée.
        $this->postJson($this->url(), [
            'locale' => 'es',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.locale.0',
                'La valeur sélectionnée pour langue est invalide.'
            );
    }

    public function test_validation_errors_are_displayed_in_english(): void
    {
        // Visiteur utilisant l'anglais.
        $this->withSession([
            'locale' => 'en',
        ]);

        $this->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.locale.0',
                'The locale field is required.'
            );
    }
}
