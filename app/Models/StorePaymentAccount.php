<?php
namespace App\Models;


use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePaymentAccount extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'method',
        'gateway',
        'merchant_account_id',
        'wallet_number',
    ];

    protected $attributes = [
        'status' => 'PENDING_VERIFICATION',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
