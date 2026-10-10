<?php

namespace App\Models;



use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'amount',
    'method',
    'status',
    'reference',
    'received_by',
    'cash_received',
    'change_given',
    'confirmed_at',
    'provider',
    'provider_transaction_id',
])]
class Payment extends Model
{
    use BelongsToStore;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cash_received' => 'decimal:2',
            'change_given' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }
}
