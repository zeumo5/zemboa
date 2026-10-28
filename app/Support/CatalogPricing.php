<?php

namespace App\Support;

use App\Actions\Orders\OrderPriceCalculator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CatalogPricing
{
    public const FIELDS = ['price', 'promo_price', 'promo_starts_at', 'promo_ends_at'];

    public const TIMEZONE = 'Africa/Douala';

    public static function writable(?User $actor): bool
    {
        if (! $actor || $actor->is_buyer !== false || $actor->store_id === null
            || ! AccountDestination::professionalIdentity($actor) || ! $actor->hasRole('STORE_OWNER')) {
            return false;
        }
        try {
            $storeId = app(TenantContext::class)->storeId();
        } catch (\RuntimeException) {
            return false;
        }

        return EmployeeAccess::owner($actor, $storeId) && $actor->hasPermission('products.update');
    }

    public static function formDate(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        if (str_contains($value, 'T')) {
            return $value;
        }
        try {
            return Carbon::parse($value, 'UTC')->timezone(self::TIMEZONE)->format('Y-m-d\TH:i');
        } catch (\Exception) {
            return '';
        }
    }

    /** Normalize submitted dates only; existing UTC values are never rewritten implicitly. */
    public static function normalizeDates(array $data): array
    {
        foreach (['promo_starts_at', 'promo_ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field])
                    ? Carbon::parse($data[$field], 'UTC')->utc()->format('Y-m-d H:i:s') : null;
            }
        }

        return $data;
    }

    /** Validate the complete persisted state, never just the submitted fragment. */
    public static function validate(array $state): void
    {
        Validator::make($state, [
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'promo_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2', 'lt:price'],
            'promo_starts_at' => ['nullable', 'date'],
            'promo_ends_at' => ['nullable', 'date'],
        ])->validate();
        $calculator = app(OrderPriceCalculator::class);
        if (isset($state['promo_price']) && $calculator->toFcfa((string) $state['promo_price']) >= $calculator->toFcfa((string) $state['price'])) {
            throw ValidationException::withMessages(['promo_price' => __('promotions.price_invalid')]);
        }
        if (! empty($state['promo_starts_at']) && ! empty($state['promo_ends_at'])
            && Carbon::parse($state['promo_ends_at'], 'UTC')->lt(Carbon::parse($state['promo_starts_at'], 'UTC'))) {
            throw ValidationException::withMessages(['promo_ends_at' => __('promotions.dates_invalid')]);
        }
    }
}
