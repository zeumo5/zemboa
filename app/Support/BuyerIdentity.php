<?php

namespace App\Support;

use App\Actions\Customers\NormalizeCustomerPhone;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class BuyerIdentity
{
    public static function assertSchemaReady(): void
    {
        $columns = collect(Schema::getColumns('users'));
        abort_unless($columns->contains('name', 'is_buyer') && $columns->contains('name', 'buyer_phone_identity')
            && ($columns->firstWhere('name', 'email')['nullable'] ?? false)
            && Schema::hasIndex('users', 'users_buyer_phone_identity_unique', 'unique'), 503, __('buyer.setup_required'));
    }

    public static function phone(string $value): string
    {
        // The customer normalizer strips arbitrary text; identity input must not.
        if (!preg_match('/\A[0-9+(). \t\r\n-]+\z/D', $value) || strlen($value) > 64) {
            throw new InvalidArgumentException('Invalid phone identifier.');
        }
        return (new NormalizeCustomerPhone())->execute($value);
    }

    public static function phoneOrNull(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        try { return self::phone($value); } catch (InvalidArgumentException) { return null; }
    }

    public static function identifier(mixed $value): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        return str_contains($value, '@') ? Str::lower($value) : (self::phoneOrNull($value) ?? $value);
    }

    /** Historical contacts remain unchanged; never pick the first ambiguous match. */
    public static function phoneQuery(string $phone): Builder
    {
        $clean = 'phone';
        foreach ([' ', '-', '(', ')', '.'] as $separator) {
            $clean = "REPLACE($clean, '$separator', '')";
        }
        foreach ([9, 10, 13] as $char) $clean = "REPLACE($clean, CHAR($char), '')";
        return User::query()->whereRaw("$clean IN (?, ?, ?)", [$phone, substr($phone, 1), substr($phone, 4)]);
    }
}
