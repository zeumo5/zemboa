<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'sku',
    'price',
    'promo_price',
    'promo_starts_at',
    'promo_ends_at',
    'is_default',
    'status',
])]
class ProductVariant extends Model
{
    use BelongsToStore;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
{
    return $this->belongsToMany(
        AttributeValue::class,
        'product_variant_attribute_value'
    )->withTimestamps();
}

public function effectivePrice(?CarbonInterface $at = null): string
{
    if ($this->promo_price === null) {
        return $this->price;
    }

    $at ??= Carbon::now();

    if (
        $this->promo_starts_at !== null
        && $at->lt($this->promo_starts_at)
    ) {
        return $this->price;
    }

    if (
        $this->promo_ends_at !== null
        && $at->gt($this->promo_ends_at)
    ) {
        return $this->price;
    }

    return $this->promo_price;
}

    

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'promo_starts_at' => 'datetime',
            'promo_ends_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }

    
}