<?php

namespace App\Models;


use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'order_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
        ];
    }

    // Commande concernée par le retour.
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // Commerçant ayant examiné la demande.
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }

    // Articles concernés par la demande.
    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
