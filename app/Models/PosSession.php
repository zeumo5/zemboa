<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSession extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'pos_register_id',
        'opening_cash',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:2',
            'closing_cash_expected' => 'decimal:2',
            'closing_cash_counted' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(
            PosRegister::class,
            'pos_register_id'
        );
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'opened_by_user_id'
        );
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'closed_by_user_id'
        );
    }
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pos_session_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'pos_session_id');
    }
}
