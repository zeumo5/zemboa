<?php

namespace App\Actions\Orders;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OrderPriceCalculator
{
    /** Largest whole-FCFA amount representable by DECIMAL(12,2). */
    public const MAX_FCFA = 9999999999;

    public function deliveryFee(mixed $amount): string
    {
        try {
            $fee = $this->toFcfa(is_scalar($amount) ? (string) $amount : '');
        } catch (ValidationException) {
            throw ValidationException::withMessages(['delivery_zone_id' => __('orders.delivery_fee_required')]);
        }
        if ($fee <= 0) {
            throw ValidationException::withMessages(['delivery_zone_id' => __('orders.delivery_fee_required')]);
        }

        return $this->formatFcfa($fee);
    }

    public function calculate(array $items, string $deliveryFee = '0.00'): array
    {
        $subtotal = 0;
        $discountAmount = 0;

        foreach ($items as $item) {
            if (filter_var($item['quantity'], FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('Invalid order quantity.');
            }
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

            if ($unitPrice > 0 && $quantity > intdiv(self::MAX_FCFA - $subtotal, $unitPrice)) {
                throw ValidationException::withMessages(['amount' => __('orders.amount_limit')]);
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

        if ($deliveryFeeAmount > self::MAX_FCFA - ($subtotal - $discountAmount)) {
            throw ValidationException::withMessages(['amount' => __('orders.amount_limit')]);
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
            throw ValidationException::withMessages(['amount' => __('orders.amount_invalid')]);
        }

        [$whole, $decimal] = array_pad(
            explode('.', $normalized, 2),
            2,
            ''
        );

        $decimal = str_pad($decimal, 2, '0');

        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 10 || (int) $whole > self::MAX_FCFA
            || ((int) $whole === self::MAX_FCFA && (int) $decimal >= 50)) {
            throw ValidationException::withMessages(['amount' => __('orders.amount_limit')]);
        }

        // FCFA : arrondi à l'unité monétaire.
        return (int) $whole + ((int) $decimal >= 50 ? 1 : 0);
    }

    private function formatFcfa(int $amount): string
    {
        return $amount.'.00';
    }
}
