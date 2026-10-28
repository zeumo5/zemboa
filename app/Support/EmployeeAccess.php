<?php

namespace App\Support;

use App\Models\User;

final class EmployeeAccess
{
    public const ROLES = ['STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT'];

    public static function owner(?User $user, int $storeId): bool
    {
        return $user !== null && $user->status === 'ACTIVE' && (int) $user->store_id === $storeId
            && $user->roles()->count() === 1 && $user->hasRole('STORE_OWNER');
    }

    public static function employee(User $user): bool
    {
        $user->loadMissing('roles');

        return $user->roles->count() === 1 && in_array($user->roles->first()->code, self::ROLES, true);
    }
}
