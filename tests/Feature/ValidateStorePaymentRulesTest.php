<?php

namespace Tests\Feature;

use App\Actions\Payments\ValidateStorePaymentRules;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ValidateStorePaymentRulesTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshIsolatedDatabase;
    }

    // Sécurité avant toute migration de test.
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

    private function prepareOrder(string $slug = 'boutique-alpha'): array
    {
        $store = Store::create([
            'name' => $slug,
            'slug' => $slug,
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
            'order_number' => 'ZM-PAY-'.$store->id,
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

    private function validatePayment(
        Order $order,
        string $alreadyPaid,
        string $newPayment
    ): int {
        return app(ValidateStorePaymentRules::class)
            ->execute($order, $alreadyPaid, $newPayment);
    }

    private function assertAmountRejected(
        Order $order,
        string $alreadyPaid,
        string $newPayment
    ): void {
        try {
            $this->validatePayment(
                $order,
                $alreadyPaid,
                $newPayment
            );

            $this->fail('Le paiement aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'amount',
                $exception->errors()
            );
        }
    }

    public function test_store_without_settings_does_not_implicitly_allow_partial_payments(): void
    {
        [$order] = $this->prepareOrder();

        $this->assertAmountRejected(
            $order,
            '0.00',
            '2000.00'
        );

    }

    public function test_full_payment_is_allowed_when_partial_disabled(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => false,
        ]);

        $result = $this->validatePayment(
            $order,
            '0.00',
            '20000.00'
        );

        $this->assertSame(2000000, $result);
    }

    public function test_partial_payment_is_rejected_when_disabled(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => false,
        ]);

        $this->assertAmountRejected(
            $order,
            '0.00',
            '5000.00'
        );
    }

    public function test_first_deposit_below_minimum_is_rejected(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => true,
            'minimum_deposit_percent' => 30,
        ]);

        // 30 % de 20 000 = 6 000 FCFA.
        $this->assertAmountRejected(
            $order,
            '0.00',
            '5000.00'
        );
    }

    public function test_first_deposit_equal_to_minimum_is_accepted(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => true,
            'minimum_deposit_percent' => 30,
        ]);

        $result = $this->validatePayment(
            $order,
            '0.00',
            '6000.00'
        );

        $this->assertSame(600000, $result);
    }

    public function test_minimum_is_not_reapplied_to_later_payment(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => true,
            'minimum_deposit_percent' => 30,
        ]);

        // Le premier acompte de 6 000 est déjà payé.
        // Le client verse ensuite 2 000 FCFA.
        $result = $this->validatePayment(
            $order,
            '6000.00',
            '2000.00'
        );

        $this->assertSame(800000, $result);
    }

    public function test_payment_cannot_exceed_remaining_balance(): void
    {
        [$order] = $this->prepareOrder();

        StoreSetting::create([
            'allow_partial_payments' => true,
        ]);

        // Solde restant : 5 000 FCFA.
        $this->assertAmountRejected(
            $order,
            '15000.00',
            '6000.00'
        );
    }

    public function test_store_cannot_validate_another_store_order(): void
    {
        [$orderA, $userA] = $this->prepareOrder(
            'boutique-alpha'
        );

        [$orderB, $userB] = $this->prepareOrder(
            'boutique-beta'
        );

        // On revient dans la boutique A.
        app(TenantContext::class)->setFromUser($userA);

        try {
            $this->validatePayment(
                $orderB,
                '0.00',
                '5000.00'
            );

            $this->fail(
                'Une autre boutique ne doit pas pouvoir valider ce paiement.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'order',
                $exception->errors()
            );
        }
    }
}
