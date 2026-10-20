
<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['code' => 'pos.sales.create'],
            ['name' => 'Créer une vente en caisse']
        );

        foreach ([
            'STORE_OWNER',
            'STORE_MANAGER',
            'CASHIER',
        ] as $roleCode) {
            $role = Role::where('code', $roleCode)->first();

            if ($role !== null) {
                // Ajouter sans retirer les permissions existantes.
                $role->permissions()->syncWithoutDetaching([
                    $permission->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Ne rien supprimer automatiquement :
        // conserver les autorisations existantes.
    }
};
