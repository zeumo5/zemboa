<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
#[Hidden(['checkout_key_hash', 'checkout_request_hash', 'checkout_actor_hash', 'pos_key_hash', 'pos_request_hash', 'pos_document_snapshot', 'preparation_started_at', 'preparation_started_by', 'ready_at', 'ready_by'])]
class Order extends Model
{
    use BelongsToStore;

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparation_started_by');
    }

    public function readyBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ready_by');
    }

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

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    protected function casts(): array
    {
        return [
            'preparation_started_at' => 'datetime',
            'ready_at' => 'datetime',
            'checkout_expires_at' => 'datetime',
            'pos_document_snapshot' => 'array',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'reservation_expires_at' => 'datetime',
            'balance_due_at' => 'datetime',
        ];
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }
}
