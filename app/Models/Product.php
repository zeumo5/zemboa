<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id',
    'name',
    'slug',
    'description',
    'status',
    'is_featured',
])]
class Product extends Model
{
    use BelongsToStore;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}