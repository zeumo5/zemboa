<?php

namespace App\Actions\Customers;

use App\Models\Customer;

class UpdateCustomer
{
    public function __construct(
        private NormalizeCustomerPhone $normalizeCustomerPhone
    ) {
    }

    public function execute(
        Customer $customer,
        string $name,
        string $phone,
        ?string $email = null,
        string $status = 'ACTIVE'
    ): Customer {
        $customer->update([
            'name' => $name,
            'phone' => $this->normalizeCustomerPhone->execute($phone),
            'email' => $email,
            'status' => $status,
        ]);

        return $customer->refresh();
    }
}