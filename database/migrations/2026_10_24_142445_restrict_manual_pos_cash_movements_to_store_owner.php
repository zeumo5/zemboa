<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('code', 'pos.cash_movements.manage')->value('id');
            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'code' => 'pos.cash_movements.manage', 'name' => 'Gérer les mouvements manuels de caisse',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $ownerIds = DB::table('roles')->where('code', 'STORE_OWNER')->pluck('id');
            DB::table('permission_role')->where('permission_id', $permissionId)->whereNotIn('role_id', $ownerIds)->delete();
            foreach ($ownerIds as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        });
    }

    public function down(): void
    {
        // A rollback must not restore permissions revoked by this security decision.
    }
};
