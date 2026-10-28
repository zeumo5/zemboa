<?php

namespace App\Support;

use App\Models\User;

final class AccountDestination
{
    public static function professionalIdentity(?User $user): bool
    {
        if (! $user || $user->status !== 'ACTIVE') {
            return false;
        }
        if ($user->canUseSuperAdminAccount()) {
            return true;
        }
        if ($user->store_id === null || $user->store()->value('status') !== 'ACTIVE' || $user->roles()->count() !== 1) {
            return false;
        }
        $role = $user->roles()->value('code');

        return in_array($role, ['STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT'], true)
            && ($role === 'STORE_OWNER' || $user->email_verified_at !== null);
    }

    public static function professionalRoute(?User $user): ?string
    {
        if (! self::professionalIdentity($user)) {
            return null;
        }
        if ($user->canUseSuperAdminAccount()) {
            return 'admin.dashboard';
        }
        $role = $user->roles()->value('code');

        return match (true) {
            in_array($role, ['STORE_OWNER', 'STORE_MANAGER'], true) && $user->hasPermission('orders.view') => 'professional.dashboard',
            $role === 'CASHIER' && $user->hasPermission('pos.sales.create') => 'pos.interface.index',
            $role === 'DELIVERY_AGENT' && $user->hasPermission('deliveries.view') => 'courier.index',
            default => null,
        };
    }
}
