<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class DeliveryAccess
{
    public static function eligibleAgent(User $user): bool
    {
        return $user->status === 'ACTIVE' && $user->email_verified_at !== null
            && $user->store_id !== null && $user->store()->value('status') === 'ACTIVE'
            && $user->roles()->count() === 1 && $user->hasRole('DELIVERY_AGENT');
    }

    public static function agent(?User $user, string $permission = 'deliveries.view'): User
    {
        if (! ($user !== null && auth('web')->id() === $user->id
            && self::eligibleAgent($user) && $user->hasPermission($permission)
            && (int) app(TenantContext::class)->storeId() === (int) $user->store_id)) {
            throw new AuthorizationException(__('deliveries.access_denied'));
        }

        return $user;
    }

    public static function viewer(?User $user): User
    {
        if (! ($user !== null && auth('web')->id() === $user->id && $user->status === 'ACTIVE'
            && $user->store_id !== null && $user->store()->value('status') === 'ACTIVE'
            && $user->roles()->count() === 1 && $user->hasPermission('deliveries.view')
            && (int) app(TenantContext::class)->storeId() === (int) $user->store_id)) {
            throw new AuthorizationException(__('deliveries.access_denied'));
        }
        if ($user->hasRole('DELIVERY_AGENT')) {
            return self::agent($user);
        }
        if (! ($user->hasRole('STORE_OWNER') || $user->hasRole('STORE_MANAGER'))) {
            throw new AuthorizationException(__('deliveries.access_denied'));
        }

        return $user;
    }

    public static function missions(User $user): Builder
    {
        return Delivery::where('store_id', $user->store_id)->where('assigned_to', $user->id)
            ->whereHas('order', fn (Builder $query) => $query->where('store_id', $user->store_id)->where('fulfillment_type', 'DELIVERY'));
    }
}
