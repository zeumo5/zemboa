<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\SalesAccess;
use App\Support\TenantContext;

final class OrderPolicy
{
    public function prepare(User $user, Order $order): bool
    {
        return SalesAccess::allowed($user, 'orders.update') && (int) $user->store_id === (int) $order->store_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->status === 'ACTIVE'
            && (int) $user->store_id === app(TenantContext::class)->storeId()
            && $user->hasPermission('orders.view') && ! $user->hasRole('SUPER_ADMIN')
            && ($user->hasRole('STORE_OWNER')
                || ($user->hasRole('STORE_MANAGER') && $user->email_verified_at !== null));
    }
}
