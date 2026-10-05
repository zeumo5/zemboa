<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'product_variant_id',
        'physical_quantity',
        'reserved_quantity',
        'low_stock_threshold',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function availableQuantity(): int
    {
        return $this->physical_quantity - $this->reserved_quantity;
    }

    public function isLowStock(): bool
    {
        return $this->availableQuantity() <= $this->low_stock_threshold;
    }

    protected function casts(): array
    {
        return [
            'physical_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }
}