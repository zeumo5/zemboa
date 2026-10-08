<?php

namespace App\Actions\Customers;

use App\Models\Customer;

class CreateCustomer
{
    public function __construct(
        private NormalizeCustomerPhone $normalizeCustomerPhone
    ) {
    }

    public function execute(
        string $name,
        string $phone,
        ?string $email = null
    ): Customer {
        $normalizedPhone = $this->normalizeCustomerPhone->execute($phone);

        return Customer::create([
            'name' => $name,
            'phone' => $normalizedPhone,
            'email' => $email,
        ]);
    }
}