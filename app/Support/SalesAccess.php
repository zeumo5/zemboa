<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SalesAccess
{
    public static function allowed(?User $actor, string $permission, bool $ownerOnly = false): bool
    {
        return AccountDestination::professionalIdentity($actor)
            && $actor->roles()->whereIn('code', $ownerOnly ? ['STORE_OWNER'] : ['STORE_OWNER', 'STORE_MANAGER'])->exists()
            && (int) $actor->store_id === app(TenantContext::class)->storeId()
            && $actor->hasPermission('orders.view') && $actor->hasPermission($permission);
    }

    /** @return array{actor: User, store: Store, settings: ?StoreSetting} */
    public static function context(Request $request): array
    {
        $permission = match (true) {
            $request->routeIs('professional.customers.*') => 'customers.view',
            $request->routeIs('professional.returns.*') => 'returns.view',
            default => 'orders.view',
        };
        $actor = $request->user('web');
        abort_unless(self::allowed($actor, $permission, $request->routeIs('professional.returns.*')), 403);

        return ['actor' => $actor, 'store' => app(TenantContext::class)->store(), 'settings' => StoreSetting::first()];
    }

    public static function preparationVersion(Order $order): string
    {
        return hash('sha256', json_encode([$order->id, $order->store_id, $order->status, $order->payment_status,
            $order->preparation_started_at, $order->preparation_started_by, $order->ready_at, $order->ready_by], JSON_THROW_ON_ERROR));
    }

    public static function filters(Request $request, array $rules = []): array
    {
        return $request->validate($rules + ['q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')]]);
    }

    public static function period(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }
    }

    public static function workflow(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $error) {
            if (app()->getLocale() !== 'en') {
                throw $error;
            }
            throw ValidationException::withMessages(array_map(fn ($messages) => [__('sales.workflow_refused')], $error->errors()));
        }
    }
}
