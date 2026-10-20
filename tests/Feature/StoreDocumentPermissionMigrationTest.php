<?php
namespace Tests\Feature;


use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class StoreDocumentPermissionMigrationTest extends TestCase
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

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_10_18_182445_add_store_document_setting_permissions.php'
        );
    }

    public function test_grants_only_owner_and_manager(): void
    {
        $this->seed(RoleSeeder::class);

        $this->migration()->up();

        foreach ([
            'store.document_settings.view',
            'store.document_settings.update',
        ] as $code) {
            $permission = Permission::where('code', $code)
                ->firstOrFail();

            foreach (['STORE_OWNER', 'STORE_MANAGER'] as $roleCode) {
                $role = Role::where('code', $roleCode)
                    ->firstOrFail();

                $this->assertDatabaseHas('permission_role', [
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                ]);
            }

            foreach ([
                'CASHIER',
                'DELIVERY_AGENT',
                'SUPER_ADMIN',
            ] as $roleCode) {
                $role = Role::where('code', $roleCode)
                    ->firstOrFail();

                $this->assertDatabaseMissing('permission_role', [
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                ]);
            }
        }
    }

    public function test_existing_permissions_are_preserved(): void
    {
        $this->seed(RoleSeeder::class);

        $legacy = Permission::firstOrCreate(
            ['code' => 'orders.view'],
            ['name' => 'Voir les commandes']
        );

        $owner = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $manager = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $owner->permissions()
            ->syncWithoutDetaching([$legacy->id]);

        $manager->permissions()
            ->syncWithoutDetaching([$legacy->id]);

        $this->migration()->up();

        foreach ([$owner, $manager] as $role) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $role->id,
                'permission_id' => $legacy->id,
            ]);

            $this->assertSame(
                3,
                DB::table('permission_role')
                    ->where('role_id', $role->id)
                    ->count()
            );
        }
    }

    
public function test_migration_is_idempotent(): void
{
    $this->seed(RoleSeeder::class);

    $migration = $this->migration();

    // Première exécution.
    $migration->up();

    $firstPermissionCount = DB::table('permissions')
        ->count();

    $firstGrantCount = DB::table('permission_role')
        ->count();

    // Deuxième exécution :
    // aucun doublon ne doit apparaître.
    $migration->up();

    $this->assertSame(
        $firstPermissionCount,
        DB::table('permissions')->count()
    );

    $this->assertSame(
        $firstGrantCount,
        DB::table('permission_role')->count()
    );

    // Vérifier seulement nos deux permissions,
    // sans compter toutes celles de ZEMBOA.
    $codes = [
        'store.document_settings.view',
        'store.document_settings.update',
    ];

    foreach ($codes as $code) {
        $this->assertSame(
            1,
            DB::table('permissions')
                ->where('code', $code)
                ->count()
        );
    }

    $permissionIds = DB::table('permissions')
        ->whereIn('code', $codes)
        ->pluck('id');

    // Chaque responsable doit recevoir les
    // deux permissions une seule fois.
    foreach ([
        'STORE_OWNER',
        'STORE_MANAGER',
    ] as $roleCode) {
        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        foreach ($permissionIds as $permissionId) {
            $this->assertSame(
                1,
                DB::table('permission_role')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permissionId)
                    ->count()
            );
        }
    }

    // 2 permissions x 2 rôles = 4 attributions.
    $this->assertSame(
        4,
        DB::table('permission_role')
            ->whereIn('permission_id', $permissionIds)
            ->count()
    );
}

    public function test_down_does_not_delete_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $migration = $this->migration();

        $migration->up();

        $migration->down();

        $this->assertDatabaseHas('permissions', [
            'code' => 'store.document_settings.view',
        ]);

        $this->assertDatabaseHas('permissions', [
            'code' => 'store.document_settings.update',
        ]);

        $this->assertSame(
            4,
            DB::table('permission_role')->count()
        );
    }
}
