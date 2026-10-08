<?php

namespace App\Rules;

use App\Actions\Customers\NormalizeCustomerPhone;
use App\Models\Customer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class UniqueCustomerPhone implements ValidationRule
{
    public function __construct(
        private ?int $ignoreCustomerId = null
    ) {
    }

    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail
    ): void {
        try {
            $normalizedPhone = app(NormalizeCustomerPhone::class)
                ->execute((string) $value);
        } catch (InvalidArgumentException) {
            // CameroonianPhone s'occupe déjà du message "numéro invalide".
            return;
        }

        $query = Customer::query()
            ->where('phone', $normalizedPhone);

        if ($this->ignoreCustomerId !== null) {
            $query->whereKeyNot($this->ignoreCustomerId);
        }

        if ($query->exists()) {
            $fail('Ce numéro de téléphone est déjà utilisé par un client de cette boutique.');
        }
    }
}