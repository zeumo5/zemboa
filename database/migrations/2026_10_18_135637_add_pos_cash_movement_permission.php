
<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['code' => 'pos.cash_movements.manage'],
            ['name' => 'Gérer les mouvements manuels de caisse']
        );

        foreach ([
            'STORE_OWNER',
            'STORE_MANAGER',
        ] as $roleCode) {
            $role = Role::where('code', $roleCode)->first();

            if ($role !== null) {
                $role->permissions()->syncWithoutDetaching([
                    $permission->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Aucun retrait automatique des permissions.
    }
};
