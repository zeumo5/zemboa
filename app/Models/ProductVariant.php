<?php

namespace App\Models;

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