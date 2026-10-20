<?php
namespace Tests\Feature;


use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PosSessionPermissionMigrationTest extends TestCase
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

    private function applyMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_10_18_085512_add_pos_session_permissions.php'
        );

        $migration->up();
    }

    public function test_session_permissions_preserve_old_grants(): void
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
        ] as $code) {
            $role = Role::where('code', $code)->firstOrFail();

            $role->permissions()->syncWithoutDetaching([
                $oldPermission->id,
            ]);

            $roles[$code] = $role;
        }

        $this->applyMigration();

        foreach ([
            'pos.sessions.open',
            'pos.sessions.close',
        ] as $code) {
            $permission = Permission::where('code', $code)
                ->firstOrFail();

            foreach ([
                'STORE_OWNER',
                'STORE_MANAGER',
                'CASHIER',
            ] as $roleCode) {
                $this->assertDatabaseHas('permission_role', [
                    'role_id' => $roles[$roleCode]->id,
                    'permission_id' => $permission->id,
                ]);
            }

            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $roles['DELIVERY_AGENT']->id,
                'permission_id' => $permission->id,
            ]);
        }

        // Les anciennes autorisations doivent rester.
        foreach ($roles as $role) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $oldPermission->id,
            ]);
        }
    }

    public function test_migration_does_not_create_duplicates(): void
    {
        $this->seed(RoleSeeder::class);

        $this->applyMigration();

        $permissionCount = DB::table('permissions')->count();
        $grantCount = DB::table('permission_role')->count();

        // Deuxième exécution uniquement dans SQLite.
        $this->applyMigration();

        $this->assertSame(
            $permissionCount,
            DB::table('permissions')->count()
        );

        $this->assertSame(
            $grantCount,
            DB::table('permission_role')->count()
        );

        foreach ([
            'pos.sessions.open',
            'pos.sessions.close',
        ] as $code) {
            $this->assertSame(
                1,
                Permission::where('code', $code)->count()
            );
        }
    }
}
