<?php
namespace Tests\Feature;


use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PosSalesPermissionMigrationTest extends TestCase
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
            'migrations/2026_10_18_114635_add_pos_sales_create_permission.php'
        );

        $migration->up();
    }

    public function test_permission_is_granted_to_authorized_roles(): void
    {
        $this->seed(RoleSeeder::class);

        // Permission préexistante à conserver.
        $existingPermission = Permission::firstOrCreate(
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
                $existingPermission->id,
            ]);

            $roles[$code] = $role;
        }

        $this->applyMigration();

        $permission = Permission::where(
            'code',
            'pos.sales.create'
        )->firstOrFail();

        // Trois rôles autorisés.
        foreach ([
            'STORE_OWNER',
            'STORE_MANAGER',
            'CASHIER',
        ] as $code) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $roles[$code]->id,
                'permission_id' => $permission->id,
            ]);
        }

        // Deux rôles interdits.
        foreach ([
            'DELIVERY_AGENT',
            'SUPER_ADMIN',
        ] as $code) {
            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $roles[$code]->id,
                'permission_id' => $permission->id,
            ]);
        }

        // Les anciennes permissions restent intactes.
        foreach ($roles as $role) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $existingPermission->id,
            ]);
        }
    }

    public function test_migration_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);

        $this->applyMigration();

        $permissionsBefore = DB::table('permissions')->count();
        $grantsBefore = DB::table('permission_role')->count();

        // Deuxième exécution dans SQLite uniquement.
        $this->applyMigration();

        $this->assertSame(
            $permissionsBefore,
            DB::table('permissions')->count()
        );

        $this->assertSame(
            $grantsBefore,
            DB::table('permission_role')->count()
        );

        $this->assertSame(
            1,
            Permission::where(
                'code',
                'pos.sales.create'
            )->count()
        );
    }
}
