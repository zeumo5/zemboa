<?php

namespace App\Policies;

use App\Models\StockLevel;
use App\Models\User;
use App\Support\AccountDestination;

class StockLevelPolicy
{
    public function viewAny(User $user): bool
    {
        return AccountDestination::professionalIdentity($user)
            && $user->roles()->whereIn('code', ['STORE_OWNER', 'STORE_MANAGER', 'CASHIER'])->exists()
            && $user->hasPermission('inventory.view');
    }

    public function view(User $user, StockLevel $stockLevel): bool
    {
        return $this->viewAny($user)
            && $user->store_id === $stockLevel->store_id;
    }

    public function manage(User $user, StockLevel $stockLevel): bool
    {
        return AccountDestination::professionalIdentity($user)
            && $user->roles()->whereIn('code', ['STORE_OWNER', 'STORE_MANAGER'])->exists()
            && $user->hasPermission('inventory.manage')
            && $user->store_id === $stockLevel->store_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StockLevel $stockLevel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StockLevel $stockLevel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StockLevel $stockLevel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StockLevel $stockLevel): bool
    {
        return false;
    }
}
