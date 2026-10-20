<?php

namespace Tests\Feature;


use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SetLocaleMiddlewareTest extends TestCase
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

        // Configuration attendue pour ZEMBOA :
        // français par défaut, anglais disponible.
        config()->set('app.locale', 'fr');

        // Route temporaire disponible uniquement
        // dans ce fichier de test.
        Route::middleware('web')->get(
            '/__tests/locale-preview',
            function () {
                return response()->json([
                    'locale' => app()->getLocale(),
                    'dashboard' => __('ui.dashboard'),
                    'orders' => __('ui.orders'),
                ]);
            }
        );
    }

    private function previewUrl(): string
    {
        return '/__tests/locale-preview';
    }

    public function test_visitor_uses_french_by_default(): void
    {
        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath(
                'dashboard',
                'Tableau de bord'
            )
            ->assertJsonPath(
                'orders',
                'Commandes'
            );
    }

    public function test_visitor_uses_english_from_session(): void
    {
        $this->withSession([
            'locale' => 'en',
        ]);

        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('dashboard', 'Dashboard')
            ->assertJsonPath('orders', 'Orders');
    }

    public function test_user_preference_overrides_session_language(): void
    {
        $user = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
        ]);

        $user->preferred_locale = 'en';
        $user->save();

        $this->actingAs($user);

        // La session demande le français,
        // mais l'utilisateur préfère l'anglais.
        $this->withSession([
            'locale' => 'fr',
        ]);

        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('dashboard', 'Dashboard');
    }

    public function test_user_without_preference_uses_session_language(): void
    {
        $user = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user);

        $this->withSession([
            'locale' => 'en',
        ]);

        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('dashboard', 'Dashboard');

        $this->assertNull(
            $user->fresh()->preferred_locale
        );
    }

    public function test_saved_language_is_applied_on_next_request(): void
    {
        $user = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user);

        // Enregistrer la préférence par notre
        // véritable route HTTP.
        $this->patchJson(
            route('account.locale.update'),
            ['locale' => 'en']
        )
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        // Nouvelle requête : l'anglais doit
        // être automatiquement activé.
        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('dashboard', 'Dashboard');

        $this->assertSame(
            'en',
            $user->fresh()->preferred_locale
        );
    }

    public function test_languages_do_not_leak_between_users(): void
    {
        $englishUser = User::factory()->create([
            'store_id' => null,
        ]);

        $frenchUser = User::factory()->create([
            'store_id' => null,
        ]);

        $englishUser->preferred_locale = 'en';
        $englishUser->save();

        $frenchUser->preferred_locale = 'fr';
        $frenchUser->save();

        // Premier utilisateur : anglais.
        $this->actingAs($englishUser);

        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        // Deuxième utilisateur : français.
        $this->actingAs($frenchUser);

        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath(
                'dashboard',
                'Tableau de bord'
            );

        // Retour au premier utilisateur.
        $this->actingAs($englishUser);

        $this->getJson($this->previewUrl())
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('dashboard', 'Dashboard');
    }
}
