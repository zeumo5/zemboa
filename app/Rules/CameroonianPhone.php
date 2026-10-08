<?php

namespace App\Rules;

use App\Actions\Customers\NormalizeCustomerPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class CameroonianPhone implements ValidationRule
{
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail
    ): void {
        try {
            app(NormalizeCustomerPhone::class)
                ->execute((string) $value);
        } catch (InvalidArgumentException) {
            $fail('Le numéro de téléphone camerounais est invalide.');
        }
    }
}