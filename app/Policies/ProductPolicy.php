<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\CatalogPricing;
use App\Support\TenantContext;

class ProductPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->status === 'ACTIVE' && $user->store_id !== null
            && $user->hasPermission('products.view')
            && (int) $user->store_id === (int) app(TenantContext::class)->storeId();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission('products.view')
            && $user->store_id === $product->store_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return CatalogPricing::writable($user) && $user->hasPermission('products.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission('products.update')
            && $user->store_id === $product->store_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission('products.delete')
            && $user->store_id === $product->store_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Product $product): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }
}
