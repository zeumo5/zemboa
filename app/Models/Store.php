<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'description',
    'slogan',
    'phone',
    'whatsapp',
    'email',
    'address',
    'status',
])]
class Store extends Model
{
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}