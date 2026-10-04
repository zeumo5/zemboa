<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'slug',
    'status',
])]
class Attribute extends Model
{
    use BelongsToStore;

    public function values(): HasMany
{
    return $this->hasMany(AttributeValue::class);
}
}