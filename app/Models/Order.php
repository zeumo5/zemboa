<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'order_number',
    'status',
    'payment_status',
    'fulfillment_type',
    'customer_name',
    'customer_phone',
    'customer_email',
    'delivery_zone_name',
    'delivery_city',
    'delivery_area',
    'delivery_address',
    'delivery_instructions',
    'subtotal',
    'discount_amount',
    'delivery_fee',
    'total',
    'reservation_expires_at',
    'balance_due_at',
])]
class Order extends Model
{
    use BelongsToStore;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'reservation_expires_at' => 'datetime',
            'balance_due_at' => 'datetime',
        ];
    }
}
