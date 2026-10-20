<?php
namespace Tests\Feature;


use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PosCashMovementInfrastructureTest extends TestCase
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

    private function applyPermissionMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_10_18_135637_add_pos_cash_movement_permission.php'
        );

        $migration->up();
    }

    public function test_permission_grants_preserve_existing_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $oldPermission = Permission::firstOrCreate(
            ['code' => 'orders.view'],
            ['name' => 'Voir les commandes']
        );

        $roles = [];

        foreach ([
            'STORE_OWNER',
            'STORE_MANAGER',
            'CASHIER',
            'DELIVERY_AGENT',
            'SUPER_ADMIN',
        ] as $code) {
            $role = Role::where('code', $code)
                ->firstOrFail();

            $role->permissions()->syncWithoutDetaching([
                $oldPermission->id,
            ]);

            $roles[$code] = $role;
        }

        $this->applyPermissionMigration();

        $permission = Permission::where(
            'code',
            'pos.cash_movements.manage'
        )->firstOrFail();

        foreach ([
            'STORE_OWNER',
            'STORE_MANAGER',
        ] as $code) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $roles[$code]->id,
                'permission_id' => $permission->id,
            ]);
        }

        foreach ([
            'CASHIER',
            'DELIVERY_AGENT',
            'SUPER_ADMIN',
        ] as $code) {
            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $roles[$code]->id,
                'permission_id' => $permission->id,
            ]);
        }

        foreach ($roles as $role) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $oldPermission->id,
            ]);
        }
    }

    public function test_permission_migration_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);

        $this->applyPermissionMigration();

        $permissionCount = DB::table('permissions')->count();
        $grantCount = DB::table('permission_role')->count();

        // Réexécution uniquement sur SQLite de test.
        $this->applyPermissionMigration();

        $this->assertSame(
            $permissionCount,
            DB::table('permissions')->count()
        );

        $this->assertSame(
            $grantCount,
            DB::table('permission_role')->count()
        );

        $this->assertSame(
            1,
            Permission::where(
                'code',
                'pos.cash_movements.manage'
            )->count()
        );
    }

    public function test_cash_movements_have_tenant_foreign_key(): void
    {
        $this->assertSame(
            1,
            (int) DB::selectOne(
                'PRAGMA foreign_keys'
            )->foreign_keys
        );

        $foreignKeys = collect(
            DB::select(
                "PRAGMA foreign_key_list('pos_cash_movements')"
            )
        )->groupBy('id');

        $found = $foreignKeys->contains(function ($group) {
            $columns = $group
                ->sortBy('seq')
                ->values();

            return
                $columns->first()->table === 'pos_sessions'
                && $columns->pluck('from')->all() === [
                    'store_id',
                    'pos_session_id',
                ]
                && $columns->pluck('to')->all() === [
                    'store_id',
                    'id',
                ];
        });

        $this->assertTrue(
            $found,
            'La contrainte doit vérifier la boutique et la session.'
        );
    }

    private function createSession(string $slug): array
    {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $registerId = DB::table('pos_registers')
            ->insertGetId([
                'store_id' => $store->id,
                'name' => 'Caisse principale',
                'code' => 'CAISSE-01',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $sessionId = DB::table('pos_sessions')
            ->insertGetId([
                'store_id' => $store->id,
                'pos_register_id' => $registerId,
                'opened_by_user_id' => $user->id,
                'status' => 'OPEN',
                'open_slot' => 1,
                'opening_cash' => '5000.00',
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'store_id' => $store->id,
            'user_id' => $user->id,
            'session_id' => $sessionId,
        ];
    }

    public function test_database_rejects_cross_store_cash_movement(): void
    {
        $storeA = $this->createSession('alpha');
        $storeB = $this->createSession('beta');

        $movement = [
            'store_id' => $storeA['store_id'],
            'pos_session_id' => $storeB['session_id'],
            'type' => 'CASH_IN',
            'amount' => '2000.00',
            'reason' => 'Test isolation',
            'reference' => 'MOV-ISOLATION-001',
            'recorded_by_user_id' => $storeA['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('pos_cash_movements')
                ->insert($movement);

            $this->fail(
                'La base doit refuser une session étrangère.'
            );
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'FOREIGN KEY',
                strtoupper($exception->getMessage())
            );
        }

        $this->assertDatabaseCount(
            'pos_cash_movements',
            0
        );

        // La même opération doit réussir avec
        // une session appartenant à la boutique A.
        $movement['pos_session_id'] = $storeA['session_id'];

        DB::table('pos_cash_movements')
            ->insert($movement);

        $this->assertDatabaseHas('pos_cash_movements', [
            'store_id' => $storeA['store_id'],
            'pos_session_id' => $storeA['session_id'],
            'reference' => 'MOV-ISOLATION-001',
        ]);
    }
}
