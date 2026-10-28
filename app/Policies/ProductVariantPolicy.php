<?php

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;
use App\Support\CatalogPricing;

class ProductVariantPolicy
{
    public function view(User $user, ProductVariant $variant): bool
    {
        return $user->hasPermission('products.view')
            && $user->store_id === $variant->store_id;
    }

    public function create(User $user): bool
    {
        return CatalogPricing::writable($user) && $user->hasPermission('products.update');
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $user->hasPermission('products.update')
            && $user->store_id === $variant->store_id;
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $user->hasPermission('products.update')
            && $user->store_id === $variant->store_id;
    }
}
