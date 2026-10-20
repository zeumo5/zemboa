
<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Créer les permissions sans doublons.
        $permissions = [
            'store.document_settings.view' =>
                'Voir les paramètres documentaires',

            'store.document_settings.update' =>
                'Modifier les paramètres documentaires',
        ];

        $permissionIds = [];

        foreach ($permissions as $code => $name) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $name]
            );

            $permissionIds[] = $permission->id;
        }

        // Attribuer uniquement aux responsables
        // autorisés, sans remplacer leurs permissions.
        foreach ([
            'STORE_OWNER',
            'STORE_MANAGER',
        ] as $roleCode) {
            $role = Role::query()
                ->where('code', $roleCode)
                ->first();

            // Une installation neuve peut ne pas
            // encore avoir exécuté ses seeders.
            if ($role === null) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching(
                $permissionIds
            );
        }
    }

    public function down(): void
    {
        // Volontairement non destructif.
        // Un rollback ne supprime pas les permissions
        // et n'enlève pas les accès déjà attribués.
    }
};
