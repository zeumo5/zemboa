
<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Créer uniquement les permissions absentes.
        $permissions = [
            'pos.sessions.open' =>
                'Ouvrir une session de caisse',

            'pos.sessions.close' =>
                'Clôturer une session de caisse',
        ];

        foreach ($permissions as $code => $name) {
            Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name]
            );
        }

        // Autoriser les rôles concernés.
        $roleCodes = [
            'STORE_OWNER',
            'STORE_MANAGER',
            'CASHIER',
        ];

        $permissionIds = Permission::query()
            ->whereIn('code', array_keys($permissions))
            ->pluck('id')
            ->all();

        foreach ($roleCodes as $roleCode) {
            $role = Role::query()
                ->where('code', $roleCode)
                ->first();

            if ($role === null) {
                continue;
            }

            // Ajouter sans retirer les permissions
            // déjà accordées au rôle.
            $role->permissions()
                ->syncWithoutDetaching($permissionIds);
        }
    }

    public function down(): void
    {
        // Aucun retrait automatique des permissions.
        // Évite de supprimer des autorisations utilisées.
    }
};
