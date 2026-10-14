<?php
namespace App\Policies;


use App\Models\StoreSetting;
use App\Models\User;

class StoreSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->store_id !== null
            && $user->hasPermission('settings.view');
    }

    public function view(
        User $user,
        StoreSetting $setting
    ): bool {
        return $user->hasPermission('settings.view')
            && $user->store_id === $setting->store_id;
    }

    public function create(User $user): bool
    {
        return $user->store_id !== null
            && $user->hasPermission('settings.update');
    }

    public function update(
        User $user,
        StoreSetting $setting
    ): bool {
        return $user->hasPermission('settings.update')
            && $user->store_id === $setting->store_id;
    }

    public function delete(
        User $user,
        StoreSetting $setting
    ): bool {
        return false;
    }
}
