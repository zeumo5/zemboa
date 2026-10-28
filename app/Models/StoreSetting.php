<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'logo_path',
    'banner_path',
    'primary_color',

    'allow_reservations',
    'reservation_duration_minutes',

    'allow_partial_payments',
    'minimum_deposit_percent',

    'delivery_enabled',
    'pickup_enabled',

    'returns_enabled',
    'return_window_days',

    'low_stock_threshold',
    'opening_hours',

    'notify_new_orders',
    'notify_low_stock',
    'default_locale',
])]
class StoreSetting extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'commercial_configured' => 'boolean',
            'allow_reservations' => 'boolean',
            'reservation_duration_minutes' => 'integer',

            'allow_partial_payments' => 'boolean',
            'minimum_deposit_percent' => 'integer',

            'delivery_enabled' => 'boolean',
            'pickup_enabled' => 'boolean',

            'returns_enabled' => 'boolean',
            'return_window_days' => 'integer',

            'low_stock_threshold' => 'integer',
            'opening_hours' => 'array',

            'notify_new_orders' => 'boolean',
            'notify_low_stock' => 'boolean',
        ];
    }

    public function hasCommercialConfiguration(): bool
    {
        return $this->commercial_configured === true;
    }
}
