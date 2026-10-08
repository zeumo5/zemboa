<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'status',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
