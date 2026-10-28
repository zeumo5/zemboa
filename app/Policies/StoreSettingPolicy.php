<?php

namespace App\Policies;

use App\Models\StoreSetting;
use App\Models\User;
use App\Support\StoreCustomizationAccess;

class StoreSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return StoreCustomizationAccess::readable($user)
            && $user->hasPermission('settings.view');
    }

    public function view(
        User $user,
        StoreSetting $setting
    ): bool {
        return $this->viewAny($user)
            && $user->store_id === $setting->store_id;
    }

    public function create(User $user): bool
    {
        return StoreCustomizationAccess::owner($user)
            && $user->hasPermission('settings.update');
    }

    public function update(
        User $user,
        StoreSetting $setting
    ): bool {
        return StoreCustomizationAccess::owner($user)
            && $user->store_id === $setting->store_id;
    }

    public function delete(
        User $user,
        StoreSetting $setting
    ): bool {
        return false;
    }
}
