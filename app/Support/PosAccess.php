<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;

final class PosAccess
{
    public static function canManageCashMovements(?User $actor): bool
    {
        return $actor !== null && auth('web')->id() === $actor->id
            && EmployeeAccess::owner($actor, app(TenantContext::class)->storeId())
            && $actor->store()->value('status') === 'ACTIVE'
            && $actor->hasPermission('pos.cash_movements.manage');
    }

    public static function authorizeCashMovements(?User $actor): User
    {
        if (! self::canManageCashMovements($actor)) {
            throw new AuthorizationException('Mouvement de caisse réservé au propriétaire autorisé.');
        }

        return $actor;
    }

    public static function authorize(?User $actor, string $permission = 'pos.sales.create'): User
    {
        abort_unless($actor !== null && auth('web')->id() === $actor->id && $actor->status === 'ACTIVE'
            && $actor->store_id !== null && $actor->store()->value('status') === 'ACTIVE'
            && $actor->roles()->count() === 1
            && $actor->roles()->whereIn('code', ['STORE_OWNER', 'STORE_MANAGER', 'CASHIER'])->exists()
            && $actor->hasPermission($permission)
            && (! $actor->hasRole('CASHIER') || $actor->email_verified_at !== null), 403);

        return $actor;
    }

    public static function schemaReady(): void
    {
        abort_unless(self::ready(), 503, __('pos_interface.setup'));
    }

    public static function ready(): bool
    {
        return Schema::hasColumns('orders', ['pos_key_hash', 'pos_request_hash', 'pos_document_snapshot', 'pos_created_by_user_id'])
            && Schema::hasIndex('orders', 'orders_pos_submission_unique', 'unique');
    }

    public static function sale(User $actor, int $id): Order
    {
        self::authorize($actor, 'orders.view');
        $query = Order::where('store_id', $actor->store_id)->whereNotNull('pos_session_id')->whereKey($id);
        if ($actor->hasRole('CASHIER')) {
            $query->whereHas('posSession', fn ($q) => $q->where('opened_by_user_id', $actor->id));
        }

        return $query->with(['items', 'payments.receivedBy', 'posSession.openedBy'])->firstOrFail();
    }
}
