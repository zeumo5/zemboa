
<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Créer les permissions POS manquantes.
        $permissions = [
            'pos.registers.view' =>
                'Consulter les caisses POS',

            'pos.registers.manage' =>
                'Gérer les caisses POS',
        ];

        foreach ($permissions as $code => $name) {
            Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name]
            );
        }

        // Attribuer les permissions selon les rôles.
        $rolePermissions = [
            'STORE_OWNER' => [
                'pos.registers.view',
                'pos.registers.manage',
            ],
            'STORE_MANAGER' => [
                'pos.registers.view',
                'pos.registers.manage',
            ],
            'CASHIER' => [
                'pos.registers.view',
            ],
        ];

        foreach ($rolePermissions as $roleCode => $codes) {
            $role = Role::query()
                ->where('code', $roleCode)
                ->first();

            if ($role === null) {
                continue;
            }

            $permissionIds = Permission::query()
                ->whereIn('code', $codes)
                ->pluck('id')
                ->all();

            // Ajouter sans supprimer les permissions
            // déjà attribuées à ce rôle.
            $role->permissions()
                ->syncWithoutDetaching($permissionIds);
        }
    }

    public function down(): void
    {
        // Retour arrière volontairement non destructif.
        // Ne pas supprimer des permissions utilisées.
    }
};
