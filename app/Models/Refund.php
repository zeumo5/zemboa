<?php
namespace App\Models;


use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'order_id',
        'return_request_id',
        'amount',
        'method',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'simulated_at' => 'datetime',
        ];
    }

    // Commande concernée.
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // Demande de retour associée.
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    // Commerçant ayant approuvé le remboursement.
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by'
        );
    }

    // Boutique concernée.
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
