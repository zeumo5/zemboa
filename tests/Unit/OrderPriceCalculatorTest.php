<?php

namespace Tests\Unit;

use App\Actions\Orders\OrderPriceCalculator;
use PHPUnit\Framework\TestCase;

class OrderPriceCalculatorTest extends TestCase
{
    public function test_it_calculates_order_totals_without_discount(): void
    {
        $calculator = new OrderPriceCalculator();

        $result = $calculator->calculate(
            items: [
                [
                    'unit_price' => '10000.00',
                    'final_unit_price' => '10000.00',
                    'quantity' => 2,
                ],
                [
                    'unit_price' => '5000.00',
                    'final_unit_price' => '5000.00',
                    'quantity' => 1,
                ],
            ],
            deliveryFee: '1500.00',
        );

        $this->assertSame('25000.00', $result['subtotal']);
        $this->assertSame('0.00', $result['discount_amount']);
        $this->assertSame('1500.00', $result['delivery_fee']);
        $this->assertSame('26500.00', $result['total']);
    }

    public function test_it_calculates_order_totals_with_discount(): void
{
    $calculator = new OrderPriceCalculator();

    $result = $calculator->calculate(
        items: [
            [
                'unit_price' => '10000.00',
                'final_unit_price' => '8000.00',
                'quantity' => 2,
            ],
            [
                'unit_price' => '5000.00',
                'final_unit_price' => '5000.00',
                'quantity' => 1,
            ],
        ],
        deliveryFee: '1500.00',
    );

    $this->assertSame('25000.00', $result['subtotal']);
    $this->assertSame('4000.00', $result['discount_amount']);
    $this->assertSame('1500.00', $result['delivery_fee']);
    $this->assertSame('22500.00', $result['total']);
}

public function test_it_rounds_monetary_amounts_to_whole_fcfa(): void
{
    $calculator = new OrderPriceCalculator();

    $result = $calculator->calculate(
        items: [
            [
                'unit_price' => '10000.00',
                'final_unit_price' => '8749.50',
                'quantity' => 2,
            ],
        ],
        deliveryFee: '500.49',
    );

    $this->assertSame('20000.00', $result['subtotal']);
    $this->assertSame('2500.00', $result['discount_amount']);
    $this->assertSame('500.00', $result['delivery_fee']);
    $this->assertSame('18000.00', $result['total']);
}

public function test_it_rejects_zero_or_negative_quantity(): void
{
    $calculator = new OrderPriceCalculator();

    $this->expectException(\InvalidArgumentException::class);

    $calculator->calculate(
        items: [
            [
                'unit_price' => '10000.00',
                'final_unit_price' => '10000.00',
                'quantity' => 0,
            ],
        ],
    );
}

public function test_it_rejects_final_price_greater_than_unit_price(): void
{
    $calculator = new OrderPriceCalculator();

    $this->expectException(\InvalidArgumentException::class);

    $calculator->calculate(
        items: [
            [
                'unit_price' => '10000.00',
                'final_unit_price' => '12000.00',
                'quantity' => 1,
            ],
        ],
    );
}

public function test_it_converts_monetary_amount_to_whole_fcfa_without_float(): void
{
    $calculator = new OrderPriceCalculator();

    $this->assertSame(8750, $calculator->toFcfa('8749.50'));
    $this->assertSame(500, $calculator->toFcfa('500.49'));
    $this->assertSame(10000, $calculator->toFcfa('10000.00'));
}

}