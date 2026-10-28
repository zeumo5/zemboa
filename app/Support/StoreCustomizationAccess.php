<?php

namespace App\Support;

use App\Models\User;

final class StoreCustomizationAccess
{
    public static function owner(?User $actor): bool
    {
        return AccountDestination::professionalIdentity($actor)
            && $actor->hasRole('STORE_OWNER')
            && $actor->hasPermission('settings.update')
            && (int) $actor->store_id === (int) app(TenantContext::class)->storeId();
    }

    public static function readable(?User $actor): bool
    {
        return AccountDestination::professionalIdentity($actor)
            && (int) $actor->store_id === (int) app(TenantContext::class)->storeId()
            && (($actor->hasRole('STORE_OWNER') && $actor->hasPermission('settings.view'))
                || $actor->hasRole('STORE_MANAGER'));
    }
}
