<?php
namespace Tests\Feature;


use App\Actions\Payments\ConfirmPayment;
use App\Actions\Pos\ClosePosSession;
use App\Actions\Pos\OpenPosSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ConfirmPosPaymentSecurityTest extends TestCase
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

    private function prepare(): array
    {
        $store = Store::create([
            'name' => 'Boutique POS Sécurité',
            'slug' => 'pos-securite',
            'status' => 'ACTIVE',
        ]);

        $cashier = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $cashierRole = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $cashier->roles()->attach($cashierRole->id);

        app(TenantContext::class)->setFromStore($store);
        $this->actingAs($cashier);

        $register = PosRegister::create([
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ])->refresh();

        $session = app(OpenPosSession::class)->execute(
            $register->id,
            '5000.00',
            $cashier
        );

        $customer = Customer::create([
            'name' => 'Client comptoir',
            'phone' => '+237690000001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'POS-SECURITY-001',
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

        // La commande provient de cette session POS.
        $order->pos_session_id = $session->id;
        $order->save();

        return compact(
            'store',
            'cashier',
            'session',
            'order'
        );
    }

    private function createPendingPayment(
        Order $order,
        ?PosSession $session
    ): Payment {
        // Création directe pour simuler aussi
        // les anciens parcours de paiement.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '24000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '25000.00',
            'change_given' => '1000.00',
        ]);

        if ($session !== null) {
            $payment->pos_session_id = $session->id;
            $payment->save();
        }

        return $payment->refresh();
    }

    public function test_closed_session_blocks_pos_payment_confirmation(): void
    {
        $data = $this->prepare();

        // Fermer d'abord une session sans paiement
        // en attente.
        app(ClosePosSession::class)->execute(
            $data['session']->id,
            '5000.00',
            $data['cashier']
        );

        // Simuler un paiement POS introduit par
        // un ancien parcours après la clôture.
        $payment = $this->createPendingPayment(
            $data['order'],
            $data['session']
        );

        try {
            app(ConfirmPayment::class)->execute(
                $payment->id,
                $data['cashier']->id
            );

            $this->fail(
                'Un paiement POS ne doit pas être confirmé après clôture.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'session',
                $exception->errors()
            );
        }

        $this->assertSame(
            'PENDING',
            $payment->fresh()->status
        );

        $this->assertSame(
            'UNPAID',
            $data['order']->fresh()->payment_status
        );
    }

    public function test_pos_order_cannot_be_paid_without_pos_session(): void
    {
        $data = $this->prepare();

        // Paiement créé sans session, comme dans
        // l'ancien parcours de paiement.
        $payment = $this->createPendingPayment(
            $data['order'],
            null
        );

        try {
            app(ConfirmPayment::class)->execute(
                $payment->id,
                $data['cashier']->id
            );

            $this->fail(
                'Une commande POS exige une session de paiement.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'session',
                $exception->errors()
            );
        }

        $this->assertSame(
            'PENDING',
            $payment->fresh()->status
        );

        $this->assertNull(
            $payment->fresh()->pos_session_id
        );
    }

    public function test_another_cashier_cannot_confirm_session_payment(): void
    {
        $data = $this->prepare();

        $payment = $this->createPendingPayment(
            $data['order'],
            $data['session']
        );

        $otherCashier = User::factory()->create([
            'store_id' => $data['store']->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $otherCashier->roles()->attach($role->id);

        $this->actingAs($otherCashier);

        try {
            app(ConfirmPayment::class)->execute(
                $payment->id,
                $otherCashier->id
            );

            $this->fail(
                'Une autre caissière ne doit pas confirmer ce paiement.'
            );
        } catch (AuthorizationException $exception) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            'PENDING',
            $payment->fresh()->status
        );
    }

    public function test_classic_payment_still_uses_existing_stock_checks(): void
    {
        $data = $this->prepare();

        // Transformer la commande de test
        // en commande classique sans session.
        $order = $data['order'];
        $order->pos_session_id = null;
        $order->save();

        $payment = $this->createPendingPayment(
            $order,
            null
        );

        // Sans réservation de stock, la confirmation
        // classique doit parvenir au contrôle du stock,
        // puis être rejetée par le mécanisme existant.
        try {
            app(ConfirmPayment::class)->execute(
                $payment->id,
                $data['cashier']->id
            );

            $this->fail(
                'Le paiement sans réservation devait être refusé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'stock',
                $exception->errors()
            );
        }

        $this->assertSame(
            'PENDING',
            $payment->fresh()->status
        );

        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );
    }
}
