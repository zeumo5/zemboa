<?php

namespace App\Models;


use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'order_id',
        'assigned_to',
        'status',
        'assigned_at',
        'picked_up_at',
        'delivered_at',
        'failed_at',
        'failure_reason',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveryAgent(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to'
        );
    }

    public function returnConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'return_confirmed_by'
        );
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }
}
