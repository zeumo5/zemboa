<?php

namespace Tests\Feature;

use App\Actions\Payments\CreateCashPayment;
use App\Actions\Pos\ClosePosSession;
use App\Actions\Pos\CreatePosCashPayment;
use App\Actions\Pos\OpenPosSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CreatePosCashPaymentTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException(
                'Tests interdits hors SQLite :memory:.'
            );
        }

        $this->refreshSafeDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function createStore(
        string $slug = 'alpha'
    ): Store {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function createActor(
        Store $store,
        string $roleCode = 'CASHIER'
    ): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function openSession(
        Store $store,
        User $actor,
        string $code = 'CAISSE-01'
    ): PosSession {
        app(TenantContext::class)->setFromStore($store);

        $this->actingAs($actor);

        $register = PosRegister::create([
            'name' => 'Caisse ' . $code,
            'code' => $code,
        ])->refresh();

        return app(OpenPosSession::class)->execute(
            $register->id,
            '5000.00',
            $actor
        );
    }

    private function createOrder(Store $store): Order
    {
        app(TenantContext::class)->setFromStore($store);

        $customer = Customer::create([
            'name' => 'Client comptoir',
            'phone' => '+237690000001',
        ]);

        return Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'POS-CASH-' . $store->id,
            'status' => 'PENDING',
            'payment_status' => 'UNPAID',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client comptoir',
            'customer_phone' => '+237690000001',
            'subtotal' => '24000.00',
            'discount_amount' => '0.00',
            'delivery_fee' => '0.00',
            'total' => '24000.00',
        ]);
    }

    private function pay(
        Store $store,
        PosSession $session,
        Order $order,
        User $actor,
        string $amount = '24000.00',
        string $cashReceived = '25000.00'
    ) {
        app(TenantContext::class)->setFromStore($store);

        $this->actingAs($actor);

        return app(CreatePosCashPayment::class)->execute(
            sessionId: $session->id,
            orderId: $order->id,
            amount: $amount,
            cashReceived: $cashReceived,
            actor: $actor
        );
    }

    public function test_cashier_creates_pending_payment_in_session(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);
        $session = $this->openSession($store, $cashier);
        $order = $this->createOrder($store);

        $payment = $this->pay(
            $store,
            $session,
            $order,
            $cashier
        );

        $this->assertSame('CASH', $payment->method);
        $this->assertSame('PENDING', $payment->status);
        $this->assertSame('24000.00', $payment->amount);
        $this->assertSame('25000.00', $payment->cash_received);
        $this->assertSame('1000.00', $payment->change_given);

        $this->assertSame(
            $session->id,
            $payment->pos_session_id
        );

        $this->assertNull($payment->confirmed_at);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'store_id' => $store->id,
            'order_id' => $order->id,
            'pos_session_id' => $session->id,
            'method' => 'CASH',
            'status' => 'PENDING',
        ]);

        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );
    }

    public function test_pending_payment_blocks_session_closing(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);
        $session = $this->openSession($store, $cashier);
        $order = $this->createOrder($store);

        $this->pay($store, $session, $order, $cashier);

        try {
            app(ClosePosSession::class)->execute(
                $session->id,
                '5000.00',
                $cashier
            );

            $this->fail(
                'La clôture aurait dû être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'session',
                $exception->errors()
            );
        }

        $this->assertSame(
            'OPEN',
            $session->fresh()->status
        );
    }

    public function test_duplicate_pending_payment_is_rejected(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);
        $session = $this->openSession($store, $cashier);
        $order = $this->createOrder($store);

        $this->pay($store, $session, $order, $cashier);

        try {
            $this->pay(
                $store,
                $session,
                $order,
                $cashier
            );

            $this->fail(
                'Un second paiement en attente ne doit pas être créé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'payment',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_closed_session_cannot_accept_payment(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);
        $session = $this->openSession($store, $cashier);
        $order = $this->createOrder($store);

        app(ClosePosSession::class)->execute(
            $session->id,
            '5000.00',
            $cashier
        );

        $this->expectException(
            ValidationException::class
        );

        $this->pay($store, $session, $order, $cashier);
    }

    public function test_other_cashier_cannot_use_session(): void
    {
        $store = $this->createStore();

        $cashierA = $this->createActor($store);
        $cashierB = $this->createActor($store);

        $session = $this->openSession(
            $store,
            $cashierA
        );

        $order = $this->createOrder($store);

        $this->expectException(
            AuthorizationException::class
        );

        $this->pay($store, $session, $order, $cashierB);
    }

    public function test_foreign_store_order_is_inaccessible(): void
    {
        $storeA = $this->createStore('alpha');
        $storeB = $this->createStore('beta');

        $cashierA = $this->createActor($storeA);
        $sessionA = $this->openSession(
            $storeA,
            $cashierA
        );

        $orderB = $this->createOrder($storeB);

        $this->expectException(
            ModelNotFoundException::class
        );

        $this->pay(
            $storeA,
            $sessionA,
            $orderB,
            $cashierA
        );
    }

    public function test_insufficient_cash_is_rejected(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);
        $session = $this->openSession($store, $cashier);
        $order = $this->createOrder($store);

        try {
            $this->pay(
                $store,
                $session,
                $order,
                $cashier,
                '24000.00',
                '20000.00'
            );

            $this->fail(
                'Le montant reçu est insuffisant.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'cash_received',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_order_and_payment_can_use_different_sessions(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);

        $sessionA = $this->openSession(
            $store,
            $cashier,
            'CAISSE-01'
        );

        $sessionB = $this->openSession(
            $store,
            $cashier,
            'CAISSE-02'
        );

        $order = $this->createOrder($store);

        // Commande créée dans CAISSE-01.
        $order->pos_session_id = $sessionA->id;
        $order->save();

        // Paiement encaissé dans CAISSE-02.
        $payment = $this->pay(
            $store,
            $sessionB,
            $order,
            $cashier
        );

        $this->assertSame(
            $sessionA->id,
            $order->fresh()->pos_session_id
        );

        $this->assertSame(
            $sessionB->id,
            $payment->pos_session_id
        );

        $this->assertNotSame(
            $order->pos_session_id,
            $payment->pos_session_id
        );
    }

    public function test_legacy_cash_action_rejects_pos_order_without_session(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);
        $session = $this->openSession($store, $cashier);
        $order = $this->createOrder($store);

        // La commande a été créée dans une session POS.
        $order->pos_session_id = $session->id;
        $order->save();

        try {
            // Tentative de contourner le POS en appelant
            // directement l'ancienne action sans session.
            app(CreateCashPayment::class)->execute(
                orderId: $order->id,
                amount: '24000.00',
                cashReceived: '25000.00'
            );

            $this->fail(
                'Un paiement POS sans session aurait dû être refusé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'session',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_legacy_cash_action_accepts_authorized_session(): void
    {
        $store = $this->createStore();
        $cashier = $this->createActor($store);
        $session = $this->openSession($store, $cashier);
        $order = $this->createOrder($store);

        // Une ancienne commande peut être encaissée
        // dans la session actuelle.
        $payment = app(CreateCashPayment::class)->execute(
            orderId: $order->id,
            amount: '24000.00',
            cashReceived: '25000.00',
            posSessionId: $session->id
        );

        $this->assertSame('PENDING', $payment->status);
        $this->assertSame('1000.00', $payment->change_given);

        $this->assertSame(
            $session->id,
            $payment->pos_session_id
        );

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'store_id' => $store->id,
            'pos_session_id' => $session->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_legacy_cash_action_rejects_another_cashiers_session(): void
    {
        $store = $this->createStore();

        $cashierA = $this->createActor($store);
        $cashierB = $this->createActor($store);

        $session = $this->openSession($store, $cashierA);
        $order = $this->createOrder($store);

        // La caissière B essaie d'utiliser
        // la session ouverte par la caissière A.
        $this->actingAs($cashierB);

        try {
            app(CreateCashPayment::class)->execute(
                orderId: $order->id,
                amount: '24000.00',
                cashReceived: '25000.00',
                posSessionId: $session->id
            );

            $this->fail(
                'Une caissière ne doit pas utiliser une autre session.'
            );
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
    }
}
