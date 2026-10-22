<?php

namespace App\Actions\Buyers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegisterBuyer
{
    public function execute(array $data): User
    {
        try {
            return DB::transaction(function () use ($data): User {
                // Identity flags and tenant are server-owned, never mass assigned from input.
                $user = new User;
                $user->forceFill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'store_id' => null,
                    'status' => 'ACTIVE',
                    'is_buyer' => true,
                    'email_verified_at' => null,
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => __('buyer.email_used')]);
        }
    }
}
