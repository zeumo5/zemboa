<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'product_variant_id',
        'quantity',
        'status',
        'reference_type',
        'reference_id',
        'expires_at',
        'converted_at',
        'released_at',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reference_id' => 'integer',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}