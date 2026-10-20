<?php
namespace Tests\Feature;


use App\Actions\Pos\CalculatePosCashBalance;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CalculatePosCashBalanceTest extends TestCase
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

    private function prepare(string $slug): array
    {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setFromStore($store);

        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $register = PosRegister::create([
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ])->refresh();

        $session = new PosSession([
            'pos_register_id' => $register->id,
            'opening_cash' => '5000.00',
        ]);

        $session->opened_by_user_id = $user->id;
        $session->save();
        $session->refresh();

        $customer = Customer::create([
            'name' => 'Client comptoir',
            'phone' => '+237690000001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'BAL-' . $store->id,
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client comptoir',
            'customer_phone' => '+237690000001',
            'subtotal' => '100000.00',
            'total' => '100000.00',
        ]);

        return compact('store', 'user', 'session', 'order');
    }

    private function addPayment(
        Order $order,
        ?PosSession $session,
        string $amount,
        string $method,
        string $status
    ): void {
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => $amount,
            'method' => $method,
            'status' => $status,
        ]);

        if ($session !== null) {
            $payment->pos_session_id = $session->id;
            $payment->save();
        }
    }

    private function addMovement(
        PosSession $session,
        User $user,
        string $type,
        string $amount,
        string $reference
    ): void {
        $movement = new PosCashMovement([
            'type' => $type,
            'amount' => $amount,
            'reason' => 'Mouvement de test',
            'reference' => $reference,
        ]);

        $movement->pos_session_id = $session->id;
        $movement->recorded_by_user_id = $user->id;
        $movement->save();
    }

    public function test_balance_counts_only_confirmed_cash(): void
    {
        $data = $this->prepare('alpha');

        $order = $data['order'];
        $session = $data['session'];
        $user = $data['user'];

        $this->addPayment(
            $order, $session,
            '24000.00', 'CASH', 'CONFIRMED'
        );

        $this->addPayment(
            $order, $session,
            '3000.00', 'CASH', 'PENDING'
        );

        $this->addPayment(
            $order, $session,
            '7000.00', 'MTN_MOMO', 'CONFIRMED'
        );

        $this->addPayment(
            $order, $session,
            '1200.00', 'ORANGE_MONEY', 'CONFIRMED'
        );

        // Un paiement sans session ne compte pas.
        $this->addPayment(
            $order, null,
            '999.00', 'CASH', 'CONFIRMED'
        );

        $this->addMovement(
            $session, $user,
            'CASH_IN', '2000.00', 'MOV-BAL-0001'
        );

        $this->addMovement(
            $session, $user,
            'CASH_OUT', '500.00', 'MOV-BAL-0002'
        );

        $balance = app(CalculatePosCashBalance::class)
            ->execute($session);

        $this->assertSame(
            '5000.00',
            $balance['opening_cash']
        );

        $this->assertSame(
            '24000.00',
            $balance['confirmed_cash_payments']
        );

        $this->assertSame(
            '2000.00',
            $balance['cash_in']
        );

        $this->assertSame(
            '500.00',
            $balance['cash_out']
        );

        $this->assertSame(
            '30500.00',
            $balance['expected_cash']
        );

        $this->assertSame(
            3050000,
            $balance['expected_cash_cents']
        );
    }

    public function test_other_store_payments_are_excluded(): void
    {
        $alpha = $this->prepare('alpha');
        $beta = $this->prepare('beta');

        $this->addPayment(
            $beta['order'],
            $beta['session'],
            '30000.00',
            'CASH',
            'CONFIRMED'
        );

        app(TenantContext::class)
            ->setFromStore($alpha['store']);

        $balance = app(CalculatePosCashBalance::class)
            ->execute($alpha['session']);

        $this->assertSame(
            '0.00',
            $balance['confirmed_cash_payments']
        );

        $this->assertSame(
            '5000.00',
            $balance['expected_cash']
        );
    }

    public function test_foreign_session_cannot_be_calculated(): void
    {
        $alpha = $this->prepare('alpha');
        $beta = $this->prepare('beta');

        app(TenantContext::class)
            ->setFromStore($alpha['store']);

        $this->expectException(
            AuthorizationException::class
        );

        app(CalculatePosCashBalance::class)
            ->execute($beta['session']);
    }
}
