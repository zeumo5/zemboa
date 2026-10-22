<?php

namespace App\Actions\Buyers;

use App\Models\User;
use App\Support\BuyerIdentity;
use Illuminate\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegisterBuyer
{
    public function execute(array $data): User
    {
        BuyerIdentity::assertSchemaReady();
        $phone = BuyerIdentity::phone($data['phone']);
        $email = isset($data['email']) && trim($data['email']) !== '' ? Str::lower(trim($data['email'])) : null;
        try {
            return DB::transaction(function () use ($data, $phone, $email): User {
                if (BuyerIdentity::phoneQuery($phone)->exists()) throw ValidationException::withMessages(['phone' => __('buyer.phone_used')]);
                if ($email !== null && User::whereRaw('LOWER(email) = ?', [$email])->exists()) throw ValidationException::withMessages(['email' => __('buyer.email_used')]);
                // Identity flags and tenant are server-owned, never mass assigned from input.
                $user = new User;
                $user->forceFill([
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $phone,
                    'password' => $data['password'],
                    'store_id' => null,
                    'status' => 'ACTIVE',
                    'is_buyer' => true,
                    'email_verified_at' => null,
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(BuyerIdentity::phoneQuery($phone)->exists()
                ? ['phone' => __('buyer.phone_used')] : ['email' => __('buyer.email_used')]);
        }
    }
}
