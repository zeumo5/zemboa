<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_variant_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'reason',
        'created_by',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
{
    static::updating(function () {
        throw new \LogicException(
            'Un mouvement de stock existant ne peut pas être modifié.'
        );
    });

    static::deleting(function () {
        throw new \LogicException(
            'Un mouvement de stock existant ne peut pas être supprimé.'
        );
    });
}

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reference_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

}