<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attribute_id',
    'value',
])]
class AttributeValue extends Model
{
    use BelongsToStore;

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function productVariants(): BelongsToMany
{
    return $this->belongsToMany(
        ProductVariant::class,
        'product_variant_attribute_value'
    )->withTimestamps();
}
}