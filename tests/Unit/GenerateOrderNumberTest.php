<?php

namespace Tests\Unit;


use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use App\Actions\Orders\GenerateOrderNumber;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GenerateOrderNumberTest extends TestCase
{

 use RefreshDatabase;

    public function test_it_generates_an_order_number_with_expected_format(): void
    {
        $orderNumber = app(GenerateOrderNumber::class)->execute();

        $this->assertMatchesRegularExpression(
            '/^ZM-\d{8}-[A-Z0-9]{5}$/',
            $orderNumber
        );
    }

    public function test_it_generates_another_number_when_the_first_one_already_exists(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $date = now()->format('Ymd');

    Order::create([
        'customer_id' => $customer->id,
        'order_number' => "ZM-{$date}-AAAAA",
        'status' => 'PENDING',
        'payment_status' => 'UNPAID',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '1000.00',
        'discount_amount' => '0.00',
        'delivery_fee' => '0.00',
        'total' => '1000.00',
    ]);

    $generator = new class extends GenerateOrderNumber
    {
        private int $call = 0;

        protected function randomPart(): string
        {
            $this->call++;

            return $this->call === 1
                ? 'AAAAA'
                : 'BBBBB';
        }
    };

    $orderNumber = $generator->execute();

    $this->assertSame(
        "ZM-{$date}-BBBBB",
        $orderNumber
    );
}

public function test_it_fails_after_too_many_order_number_collisions(): void
{
    $this->expectException(\RuntimeException::class);

    $generator = new class extends GenerateOrderNumber
    {
        protected function randomPart(): string
        {
            return 'AAAAA';
        }
    };

    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $date = now()->format('Ymd');

    Order::create([
        'customer_id' => $customer->id,
        'order_number' => "ZM-{$date}-AAAAA",
        'status' => 'PENDING',
        'payment_status' => 'UNPAID',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '1000.00',
        'discount_amount' => '0.00',
        'delivery_fee' => '0.00',
        'total' => '1000.00',
    ]);

    $generator->execute();
}
    
}