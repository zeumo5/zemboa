<?php
namespace Tests\Feature;


use App\Actions\Payments\CreateCashPayment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateCashPaymentTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshIsolatedDatabase;
    }

    public function refreshDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'SECURITE ZEMBOA : SQLite :memory: obligatoire.'
            );
        }

        $this->refreshIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function prepareOrder(
        string $slug = 'boutique-alpha',
        string $roleCode = 'STORE_OWNER'
    ): array {
        $store = Store::create([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        $customer = Customer::create([
            'name' => 'Jean Client',
            'phone' => '+237690000001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-CASH-' . $store->id,
            'status' => 'PENDING',
            'payment_status' => 'UNPAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '20000.00',
            'discount_amount' => '0.00',
            'delivery_fee' => '0.00',
            'total' => '20000.00',
        ]);

        return [$order, $user];
    }

    private function createCash(
        Order $order,
        string $amount,
        string $received
    ): Payment {
        return app(CreateCashPayment::class)->execute(
            orderId: $order->id,
            amount: $amount,
            cashReceived: $received
        );
    }

    private function assertRejected(
        Order $order,
        string $amount,
        string $received,
        string $field
    ): void {
        try {
            $this->createCash($order, $amount, $received);

            $this->fail(
                'Ce paiement aurait dû être refusé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }

    public function test_owner_can_create_full_cash_payment(): void
    {
        [$order] = $this->prepareOrder();

        $payment = $this->createCash(
            $order,
            '20000.00',
            '25000.00'
        );

        $this->assertSame('PENDING', $payment->status);
        $this->assertSame('CASH', $payment->method);
        $this->assertSame('20000.00', $payment->amount);
        $this->assertSame('5000.00', $payment->change_given);
        $this->assertSame($order->store_id, $payment->store_id);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_deposit_below_minimum_is_rejected(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => true,
            'minimum_deposit_percent' => 30,
        ]);

        $this->assertRejected(
            $order,
            '5000.00',
            '5000.00',
            'amount'
        );

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_deposit_equal_to_minimum_is_accepted(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => true,
            'minimum_deposit_percent' => 30,
        ]);

        $payment = $this->createCash(
            $order,
            '6000.00',
            '7000.00'
        );

        $this->assertSame('6000.00', $payment->amount);
        $this->assertSame('1000.00', $payment->change_given);
        $this->assertSame('PENDING', $payment->status);
    }

    public function test_partial_disabled_rejects_deposit(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => false,
        ]);

        $this->assertRejected(
            $order,
            '5000.00',
            '5000.00',
            'amount'
        );

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_full_payment_allowed_when_partial_disabled(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => false,
        ]);

        $payment = $this->createCash(
            $order,
            '20000.00',
            '20000.00'
        );

        $this->assertSame('PENDING', $payment->status);
        $this->assertSame('20000.00', $payment->amount);
    }

    public function test_duplicate_pending_payment_is_rejected(): void
    {
        [$order] = $this->prepareOrder();

        $this->createCash(
            $order,
            '5000.00',
            '5000.00'
        );

        $this->assertRejected(
            $order,
            '3000.00',
            '3000.00',
            'payment'
        );

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_delivery_agent_cannot_create_cash_payment(): void
    {
        [$order] = $this->prepareOrder(
            'boutique-alpha',
            'DELIVERY_AGENT'
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->createCash(
            $order,
            '20000.00',
            '20000.00'
        );
    }

    public function test_store_cannot_create_payment_for_another_store(): void
    {
        [$orderA, $userA] = $this->prepareOrder(
            'boutique-alpha'
        );

        [$orderB] = $this->prepareOrder(
            'boutique-beta'
        );

        $this->actingAs($userA);

        app(TenantContext::class)->setFromUser($userA);

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->createCash(
            $orderB,
            '5000.00',
            '5000.00'
        );
    }

    public function test_insufficient_cash_is_rejected(): void
    {
        [$order] = $this->prepareOrder();

        $this->assertRejected(
            $order,
            '10000.00',
            '8000.00',
            'cash_received'
        );

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_later_payment_after_confirmed_deposit(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => true,
            'minimum_deposit_percent' => 30,
        ]);

        // Acompte déjà confirmé de 6 000 FCFA.
        Payment::create([
            'order_id' => $order->id,
            'amount' => '6000.00',
            'method' => 'CASH',
            'status' => 'CONFIRMED',
            'cash_received' => '6000.00',
            'change_given' => '0.00',
            'confirmed_at' => now(),
        ]);

        $payment = $this->createCash(
            $order,
            '2000.00',
            '2000.00'
        );

        $this->assertSame('2000.00', $payment->amount);
        $this->assertSame('PENDING', $payment->status);

        $this->assertDatabaseCount('payments', 2);
    }
}
