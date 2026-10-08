<?php

namespace App\Actions\Orders;

use InvalidArgumentException;

class OrderPriceCalculator
{
    public function calculate(array $items, string $deliveryFee = '0.00'): array
    {
        $subtotal = 0;
        $discountAmount = 0;

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];

            if ($quantity <= 0) {
                throw new InvalidArgumentException(
                    'Order item quantity must be greater than zero.'
                );
            }

            $unitPrice = $this->toFcfa($item['unit_price']);
            $finalUnitPrice = $this->toFcfa($item['final_unit_price']);

            if ($unitPrice < 0 || $finalUnitPrice < 0) {
                throw new InvalidArgumentException(
                    'Order prices cannot be negative.'
                );
            }

            if ($finalUnitPrice > $unitPrice) {
                throw new InvalidArgumentException(
                    'Final unit price cannot exceed unit price.'
                );
            }

            $subtotal += $unitPrice * $quantity;
            $discountAmount += ($unitPrice - $finalUnitPrice) * $quantity;
        }

        $deliveryFeeAmount = $this->toFcfa($deliveryFee);

        if ($deliveryFeeAmount < 0) {
            throw new InvalidArgumentException(
                'Delivery fee cannot be negative.'
            );
        }

        $total = $subtotal - $discountAmount + $deliveryFeeAmount;

        return [
            'subtotal' => $this->formatFcfa($subtotal),
            'discount_amount' => $this->formatFcfa($discountAmount),
            'delivery_fee' => $this->formatFcfa($deliveryFeeAmount),
            'total' => $this->formatFcfa($total),
        ];
    }

    public function toFcfa(string|int $amount): int
    {
        $normalized = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException(
                'Invalid monetary amount.'
            );
        }

        [$whole, $decimal] = array_pad(
            explode('.', $normalized, 2),
            2,
            ''
        );

        $decimal = str_pad($decimal, 2, '0');

        // FCFA : arrondi à l'unité monétaire.
        return (int) $whole + ((int) $decimal >= 50 ? 1 : 0);
    }

    private function formatFcfa(int $amount): string
    {
        return $amount . '.00';
    }
}