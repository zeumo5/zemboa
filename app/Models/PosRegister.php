<?php
namespace App\Models;


use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class PosRegister extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'name',
        'code',
        'status',
    ];
}
