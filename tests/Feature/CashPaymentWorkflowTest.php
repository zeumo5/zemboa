<?php
namespace Tests\Feature;


use App\Actions\Orders\CreateOrder;
use App\Actions\Payments\CreateCashPayment;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashPaymentWorkflowTest extends TestCase
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

    private function prepareOrder(): array
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $role = Role::where('code', 'STORE_OWNER')
            ->firstOrFail();

        $user->roles()->attach($role->id);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        $settings = StoreSetting::create([
            'allow_partial_payments' => true,
            'minimum_deposit_percent' => 30,
        ]);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit test',
            'slug' => 'produit-test',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)
            ->execute([
                'product_id' => $product->id,
                'sku' => 'CASH-FLOW-001',
                'price' => '10000.00',
                'is_default' => true,
                'status' => 'ACTIVE',
            ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial'
        );

        $order = app(CreateOrder::class)->execute(
            customerName: 'Jean Client',
            customerPhone: '690000001',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        );

        return [$order, $variant, $user, $settings];
    }

    public function test_deposit_then_balance_converts_stock_once(): void
    {
        [$order, $variant, $user] = $this->prepareOrder();

        $this->assertSame('20000.00', $order->total);

        // Premier paiement : acompte de 30 %.
        $deposit = app(CreateCashPayment::class)
            ->execute(
                orderId: $order->id,
                amount: '6000.00',
                cashReceived: '6000.00'
            );

        $dueDate = now()
            ->addDays(3)
            ->format('Y-m-d H:i:s');

        app(ConfirmPayment::class)->execute(
            paymentId: $deposit->id,
            userId: $user->id,
            balanceDueAt: $dueDate
        );

        $this->assertSame(
            'CONFIRMED',
            $deposit->fresh()->status
        );

        $this->assertSame(
            'PARTIALLY_PAID',
            $order->fresh()->payment_status
        );

        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stock->physical_quantity);
        $this->assertSame(2, $stock->reserved_quantity);

        // Deuxième paiement : règlement du solde.
        $balance = app(CreateCashPayment::class)
            ->execute(
                orderId: $order->id,
                amount: '14000.00',
                cashReceived: '15000.00'
            );

        $this->assertSame(
            '1000.00',
            $balance->change_given
        );

        app(ConfirmPayment::class)->execute(
            paymentId: $balance->id,
            userId: $user->id
        );

        $this->assertSame(
            'CONFIRMED',
            $balance->fresh()->status
        );

        $this->assertSame(
            'PAID',
            $order->fresh()->payment_status
        );

        $stock->refresh();

        $this->assertSame(8, $stock->physical_quantity);
        $this->assertSame(0, $stock->reserved_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'SALE',
            'quantity' => 2,
        ]);

        $this->assertSame(
            1,
            $variant->stockMovements()
                ->where('type', 'SALE')
                ->count()
        );
    }

    public function test_settings_change_does_not_reject_pending_deposit(): void
    {
        [$order, $variant, $user, $settings] =
            $this->prepareOrder();

        // Acompte créé sous les règles initiales.
        $deposit = app(CreateCashPayment::class)
            ->execute(
                orderId: $order->id,
                amount: '6000.00',
                cashReceived: '6000.00'
            );

        // Le commerçant modifie ensuite ses préférences.
        $settings->update([
            'allow_partial_payments' => false,
            'minimum_deposit_percent' => 70,
        ]);

        // Le paiement déjà engagé doit rester confirmable.
        app(ConfirmPayment::class)->execute(
            paymentId: $deposit->id,
            userId: $user->id,
            balanceDueAt: now()
                ->addDays(3)
                ->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            'CONFIRMED',
            $deposit->fresh()->status
        );

        $this->assertSame(
            'PARTIALLY_PAID',
            $order->fresh()->payment_status
        );

        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stock->physical_quantity);
        $this->assertSame(2, $stock->reserved_quantity);
    }
}
