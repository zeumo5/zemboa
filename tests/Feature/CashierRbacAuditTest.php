<?php
namespace Tests\Feature;

use App\Models\Store;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Support\TenantContext;
use App\Actions\Payments\CreateCashPayment;
use App\Actions\Payments\ConfirmPayment;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CashierRbacAuditTest extends TestCase
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

    public function test_cashier_has_only_required_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $cashier = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $actual = $cashier->permissions()
            ->pluck('code')
            ->sort()
            ->values()
            ->all();

        $expected = [
            'orders.view',
            'payments.confirm',
            'payments.view',
        ];

        $this->assertSame($expected, $actual);
    }

    public function test_delivery_agent_has_no_cash_permission(): void
    {
        $this->seed(DatabaseSeeder::class);

        $delivery = Role::where(
            'code',
            'DELIVERY_AGENT'
        )->firstOrFail();

        $this->assertFalse(
            $delivery->permissions()
                ->where('code', 'payments.confirm')
                ->exists()
        );
    }

    public function test_cashier_cannot_gain_owner_or_admin_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::factory()->create([
            'status' => 'ACTIVE',
        ]);

        $cashier = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $user->roles()->attach($cashier->id);

        $this->assertTrue($user->hasRole('CASHIER'));
        $this->assertTrue(
            $user->hasPermission('payments.confirm')
        );

        $this->assertFalse(
            $user->hasRole('STORE_OWNER')
        );

        $this->assertFalse(
            $user->hasRole('SUPER_ADMIN')
        );

        $this->assertFalse(
            $user->hasPermission('refunds.manage')
        );

        $this->assertFalse(
            $user->hasPermission('settings.update')
        );
    }

private function prepareMixedRoleCashier(): array
{
    $this->seed(DatabaseSeeder::class);

    $store = Store::create([
        'name' => 'Boutique Audit Caisse',
        'slug' => 'audit-caisse',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'status' => 'ACTIVE',
    ]);

    // Cas sensible : deux rôles attribués au même utilisateur.
    $roleIds = Role::query()
        ->whereIn('code', ['CASHIER', 'DELIVERY_AGENT'])
        ->pluck('id')
        ->all();

    $user->roles()->attach($roleIds);

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Client Audit',
        'phone' => '+237690000001',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-AUDIT-CASH-001',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Audit',
        'customer_phone' => '+237690000001',
        'subtotal' => '5000.00',
        'total' => '5000.00',
    ]);

    $this->assertTrue($user->hasRole('CASHIER'));
    $this->assertTrue($user->hasRole('DELIVERY_AGENT'));
    $this->assertTrue(
        $user->hasPermission('payments.confirm')
    );

    return [$user, $order];
}

public function test_delivery_agent_with_cashier_role_cannot_create_cash_payment(): void
{
    [$user, $order] = $this->prepareMixedRoleCashier();

    try {
        app(CreateCashPayment::class)->execute(
            orderId: $order->id,
            amount: '5000.00',
            cashReceived: '5000.00'
        );

        $this->fail(
            'Un livreur ne doit pas enregistrer un paiement CASH.'
        );
    } catch (AuthorizationException $exception) {
        // Refus attendu.
    }

    $this->assertDatabaseMissing('payments', [
        'order_id' => $order->id,
        'method' => 'CASH',
    ]);
}

public function test_delivery_agent_with_cashier_role_cannot_confirm_cash_payment(): void
{
    [$user, $order] = $this->prepareMixedRoleCashier();

    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => '5000.00',
        'method' => 'CASH',
        'status' => 'PENDING',
        'cash_received' => '5000.00',
        'change_given' => '0.00',
    ]);

    try {
        app(ConfirmPayment::class)->execute(
            paymentId: $payment->id,
            userId: $user->id
        );

        $this->fail(
            'Un livreur ne doit pas confirmer un paiement CASH.'
        );
    } catch (AuthorizationException $exception) {
        // Refus attendu.
    }

    $payment->refresh();
    $order->refresh();

    $this->assertSame('PENDING', $payment->status);
    $this->assertNull($payment->confirmed_at);
    $this->assertSame('UNPAID', $order->payment_status);
}

}
