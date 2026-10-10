<?php

namespace Tests\Feature;


use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_is_attached_to_current_store_and_order(): void
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

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-PAY-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '1500.00',
            'total' => '1500.00',
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '1500.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '2000.00',
            'change_given' => '500.00',
            'received_by' => $user->id,
        ]);

        $payment->refresh();

        $this->assertSame($store->id, $payment->store_id);
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame('1500.00', $payment->amount);
        $this->assertSame('2000.00', $payment->cash_received);
        $this->assertSame('500.00', $payment->change_given);

        $this->assertTrue($payment->order->is($order));
        $this->assertTrue($payment->receivedBy->is($user));

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'store_id' => $store->id,
            'order_id' => $order->id,
            'method' => 'CASH',
            'status' => 'PENDING',
        ]);
    }

    public function test_order_can_have_multiple_payments(): void
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

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-PAY-002',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        Payment::create([
            'order_id' => $order->id,
            'amount' => '4000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
        ]);

        Payment::create([
            'order_id' => $order->id,
            'amount' => '6000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
        ]);

        $this->assertCount(2, $order->payments);
    }

    public function test_payment_queries_are_isolated_by_store(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Boutique A
        app(TenantContext::class)->setFromUser($userA);

        $customerA = Customer::create([
            'name' => 'Client Alpha',
            'phone' => '+237690000001',
        ]);

        $orderA = Order::create([
            'customer_id' => $customerA->id,
            'order_number' => 'ZM-PAY-A',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Alpha',
            'customer_phone' => '+237690000001',
            'subtotal' => '5000.00',
            'total' => '5000.00',
        ]);

        $paymentA = Payment::create([
            'order_id' => $orderA->id,
            'amount' => '5000.00',
            'method' => 'CASH',
        ]);

        // Boutique B
        app(TenantContext::class)->setFromUser($userB);

        $customerB = Customer::create([
            'name' => 'Client Beta',
            'phone' => '+237690000002',
        ]);

        $orderB = Order::create([
            'customer_id' => $customerB->id,
            'order_number' => 'ZM-PAY-B',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Beta',
            'customer_phone' => '+237690000002',
            'subtotal' => '7000.00',
            'total' => '7000.00',
        ]);

        $paymentB = Payment::create([
            'order_id' => $orderB->id,
            'amount' => '7000.00',
            'method' => 'CASH',
        ]);

        // Retour dans Boutique A
        app(TenantContext::class)->setFromUser($userA);

        $payments = Payment::query()->get();

        $this->assertTrue($payments->contains('id', $paymentA->id));
        $this->assertFalse($payments->contains('id', $paymentB->id));
        $this->assertCount(1, $payments);
    }
}
