<?php

namespace App\Actions\Customers;

use App\Models\Customer;

class FindOrCreateCustomer
{
    public function __construct(
        private readonly NormalizeCustomerPhone $normalizeCustomerPhone,
       
    ) {
    }

    public function execute(
        string $name,
        string $phone,
        ?string $email = null,
    ): Customer {
        $normalizedPhone = $this->normalizeCustomerPhone->execute($phone);

        $customer = Customer::query()
            ->where('phone', $normalizedPhone)
            ->first();

        if ($customer !== null) {
            return $customer;
        }

        return Customer::create([
            'name' => $name,
            'phone' => $normalizedPhone,
            'email' => $email,
        ]);
    }
}