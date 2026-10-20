<?php
namespace Tests\Feature;


use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PosRegisterPermissionMigrationTest extends TestCase
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

    private function runPermissionMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_10_18_075156_add_pos_register_permissions.php'
        );

        $migration->up();
    }

    public function test_new_permissions_preserve_existing_grants(): void
    {
        $this->seed(RoleSeeder::class);

        // Simuler une permission déjà attribuée.
        $legacy = Permission::query()->firstOrCreate(
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
            $role = Role::query()
                ->where('code', $code)
                ->firstOrFail();

            $role->permissions()->attach($legacy->id);

            $roles[$code] = $role;
        }

        // Réappliquer uniquement sur SQLite.
        $this->runPermissionMigration();

        $view = Permission::query()
            ->where('code', 'pos.registers.view')
            ->firstOrFail();

        $manage = Permission::query()
            ->where('code', 'pos.registers.manage')
            ->firstOrFail();

        foreach (['STORE_OWNER', 'STORE_MANAGER'] as $code) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $roles[$code]->id,
                'permission_id' => $view->id,
            ]);

            $this->assertDatabaseHas('permission_role', [
                'role_id' => $roles[$code]->id,
                'permission_id' => $manage->id,
            ]);
        }

        $this->assertDatabaseHas('permission_role', [
            'role_id' => $roles['CASHIER']->id,
            'permission_id' => $view->id,
        ]);

        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $roles['CASHIER']->id,
            'permission_id' => $manage->id,
        ]);

        foreach ([$view, $manage] as $permission) {
            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $roles['DELIVERY_AGENT']->id,
                'permission_id' => $permission->id,
            ]);
        }

        // Les anciennes permissions restent attribuées.
        foreach ($roles as $role) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $legacy->id,
            ]);
        }
    }

    public function test_permission_migration_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);

        $this->runPermissionMigration();

        $permissionsBefore = DB::table('permissions')->count();
        $grantsBefore = DB::table('permission_role')->count();

        // Deuxième exécution volontaire sur SQLite.
        $this->runPermissionMigration();

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
            Permission::query()
                ->where('code', 'pos.registers.view')
                ->count()
        );

        $this->assertSame(
            1,
            Permission::query()
                ->where('code', 'pos.registers.manage')
                ->count()
        );
    }
}
