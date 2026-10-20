<?php
namespace Tests\Feature;


use App\Actions\Pos\ClosePosSession;
use App\Actions\Pos\OpenPosSession;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ClosePosSessionRollbackTest extends TestCase
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

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_failed_closing_rolls_back_all_session_changes(): void
    {
        // 1. Préparer une boutique active.
        $store = Store::create([
            'name' => 'Boutique Test Rollback',
            'slug' => 'boutique-test-rollback',
            'status' => 'ACTIVE',
        ]);

        $owner = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $owner->roles()->attach($role->id);

        app(TenantContext::class)->setFromStore($store);

        $this->actingAs($owner);

        // 2. Créer la caisse.
        $register = PosRegister::create([
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ])->refresh();

        // 3. Ouvrir une session avec 5 000 FCFA.
        $session = app(OpenPosSession::class)->execute(
            $register->id,
            '5000.00',
            $owner
        );

        $this->assertSame('OPEN', $session->status);

        // 4. Injecter une panne après l'écriture SQL
        // de la clôture, mais avant le commit.
        $failureTriggered = false;

        PosSession::saved(function (
            PosSession $updated
        ) use (
            $session,
            &$failureTriggered
        ): void {
            if (
                (int) $updated->id !== (int) $session->id ||
                $updated->status !== 'CLOSED'
            ) {
                return;
            }

            // Vérifier que l'écriture a effectivement
            // eu lieu dans la transaction courante.
            $this->assertSame(
                'CLOSED',
                DB::table('pos_sessions')
                    ->where('id', $session->id)
                    ->value('status')
            );

            $failureTriggered = true;

            throw new RuntimeException(
                'SIMULATED_POS_CLOSING_FAILURE'
            );
        });

        // 5. Tenter de clôturer avec 7 000 FCFA.
        try {
            app(ClosePosSession::class)->execute(
                $session->id,
                '7000.00',
                $owner
            );

            $this->fail(
                'La panne simulée aurait dû interrompre la clôture.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'SIMULATED_POS_CLOSING_FAILURE',
                $exception->getMessage()
            );
        }

        $this->assertTrue($failureTriggered);

        // 6. Après rollback, la session doit
        // être exactement comme avant la clôture.
        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'store_id' => $store->id,
            'status' => 'OPEN',
            'open_slot' => 1,
            'closing_cash_expected' => null,
            'closing_cash_counted' => null,
            'cash_difference' => null,
            'closed_by_user_id' => null,
            'closed_at' => null,
        ]);

        // 7. La caisse reste occupée par
        // la session ouverte.
        $this->assertDatabaseCount('pos_sessions', 1);

        $this->assertSame(
            'OPEN',
            $session->fresh()->status
        );

        $this->assertSame(
            1,
            (int) $session->fresh()->open_slot
        );
    }
}
