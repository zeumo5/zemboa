<?php
namespace Tests\Feature;


use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class UserLocaleHttpTest extends TestCase
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

    private function url(): string
    {
        return route('account.locale.update');
    }

    public function test_guest_cannot_change_language(): void
    {
        $this->patchJson($this->url(), [
            'locale' => 'en',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_switch_languages(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user);

        // Passer en anglais.
        $this->patchJson($this->url(), [
            'locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath(
                'message',
                'Language updated successfully.'
            );

        $this->assertSame(
            'en',
            $user->fresh()->preferred_locale
        );

        // Revenir au français.
        $this->patchJson($this->url(), [
            'locale' => 'fr',
        ])
            ->assertOk()
            ->assertJsonPath('locale', 'fr')
            ->assertJsonPath(
                'message',
                'Langue mise à jour avec succès.'
            );

        $this->assertSame(
            'fr',
            $user->fresh()->preferred_locale
        );

        // Le changement de langue ne doit jamais
        // modifier l'appartenance à une boutique.
        $this->assertSame(
            $store->id,
            $user->fresh()->store_id
        );
    }

    public function test_user_without_store_can_change_language(): void
    {
        // Cas des utilisateurs sans boutique.
        $user = User::factory()->create([
            'store_id' => null,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user);

        $this->patchJson($this->url(), [
            'locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        $this->assertSame(
            'en',
            $user->fresh()->preferred_locale
        );

        $this->assertNull(
            $user->fresh()->store_id
        );
    }

    public function test_user_cannot_change_another_users_language(): void
    {
        $userA = User::factory()->create([
            'store_id' => null,
        ]);

        $userB = User::factory()->create([
            'store_id' => null,
        ]);

        // La langue personnelle de B est française.
        $userB->preferred_locale = 'fr';
        $userB->save();

        $this->actingAs($userA);

        // Le client tente de fournir l'identifiant
        // d'un autre utilisateur.
        $this->patchJson($this->url(), [
            'locale' => 'en',
            'user_id' => $userB->id,
        ])
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        // Seul l'utilisateur authentifié change.
        $this->assertSame(
            'en',
            $userA->fresh()->preferred_locale
        );

        $this->assertSame(
            'fr',
            $userB->fresh()->preferred_locale
        );
    }

    public function test_invalid_languages_are_rejected(): void
    {
        $user = User::factory()->create([
            'store_id' => null,
        ]);

        $this->actingAs($user);

        foreach ([
            'es',
            'FR',
            'de',
            '',
        ] as $locale) {
            $this->patchJson($this->url(), [
                'locale' => $locale,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['locale']);
        }

        // Champ obligatoire absent.
        $this->patchJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['locale']);

        // Aucune préférence invalide enregistrée.
        $this->assertNull(
            $user->fresh()->preferred_locale
        );
    }
}
