<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Support\Str;

class GenerateOrderNumber
{
    public function execute(): string
    {
        $date = now()->format('Ymd');
        $maxAttempts = 10;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $orderNumber = "ZM-{$date}-{$this->randomPart()}";

            $exists = Order::withoutGlobalScopes()
                ->where('order_number', $orderNumber)
                ->exists();

            if (! $exists) {
                return $orderNumber;
            }
        }

        throw new \RuntimeException(
            'Unable to generate a unique order number after 10 attempts.'
        );
    }

    protected function randomPart(): string
    {
        return Str::upper(Str::random(5));
    }
}
