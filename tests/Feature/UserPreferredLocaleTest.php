<?php
namespace Tests\Feature;


use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UserPreferredLocaleTest extends TestCase
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

    public function test_preferred_locale_column_exists_and_defaults_to_null(): void
    {
        $this->assertTrue(
            Schema::hasColumn('users', 'preferred_locale')
        );

        $user = User::factory()->create();

        $this->assertNull(
            $user->fresh()->preferred_locale
        );
    }

    public function test_user_can_save_french_and_english_preferences(): void
    {
        $user = User::factory()->create();

        // Préférence française.
        $user->preferred_locale = 'fr';
        $user->save();

        $this->assertSame(
            'fr',
            $user->fresh()->preferred_locale
        );

        // Changement vers l'anglais.
        $user->preferred_locale = 'en';
        $user->save();

        $this->assertSame(
            'en',
            $user->fresh()->preferred_locale
        );

        // Retour à la langue par défaut.
        $user->preferred_locale = null;
        $user->save();

        $this->assertNull(
            $user->fresh()->preferred_locale
        );
    }

    public function test_users_from_different_stores_have_independent_languages(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        $userA->preferred_locale = 'fr';
        $userA->save();

        $userB->preferred_locale = 'en';
        $userB->save();

        $this->assertSame(
            'fr',
            $userA->fresh()->preferred_locale
        );

        $this->assertSame(
            'en',
            $userB->fresh()->preferred_locale
        );

        // Modifier A ne doit jamais modifier B.
        $userA->preferred_locale = 'en';
        $userA->save();

        $this->assertSame(
            'en',
            $userB->fresh()->preferred_locale
        );
    }

    public function test_preferred_locale_is_not_mass_assignable(): void
    {
        $user = new User();

        // La modification de langue passera
        // par un endpoint dédié et sécurisé.
        $this->assertFalse(
            $user->isFillable('preferred_locale')
        );
    }
}
