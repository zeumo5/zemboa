<?php

namespace Tests\Feature;

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Pos\CreatePosCashPayment;
use App\Actions\Pos\CreatePosOrder;
use App\Actions\Pos\OpenPosSession;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\PosRegister;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CreatePosOrderIntegrationTest extends TestCase
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

    public function test_pos_sale_decrements_stock_only_after_cash_confirmation(): void
    {
        // 1. Boutique active.
        $store = Store::create([
            'name' => 'Boutique POS Integration',
            'slug' => 'boutique-pos-integration',
            'status' => 'ACTIVE',
        ]);

        // 2. Caissière autorisée.
        $cashier = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $cashier->roles()->attach($role->id);

        app(TenantContext::class)->setFromStore($store);
        StoreSetting::create(['pickup_enabled' => true]);

        $this->actingAs($cashier);

        // 3. Caisse active.
        $register = PosRegister::create([
            'name' => 'Caisse principale',
            'code' => 'CAISSE-01',
        ])->refresh();

        // 4. Ouverture de session.
        $session = app(OpenPosSession::class)->execute(
            $register->id,
            '5000.00',
            $cashier
        );

        $this->assertSame('OPEN', $session->status);

        // 5. Catégorie.
        $category = Category::create([
            'name' => 'Accessoires',
            'slug' => 'accessoires-pos',
            'status' => 'ACTIVE',
        ]);

        // 6. Produit réel.
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Écouteurs Bluetooth',
            'slug' => 'ecouteurs-bluetooth-pos',
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        // 7. Variante réelle avec SKU.
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'POS-ECOUTEURS-001',
            'price' => '12000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // 8. Garantir l'existence du niveau de stock.
        $stock = StockLevel::query()
            ->where('product_variant_id', $variant->id)
            ->first();

        if ($stock === null) {
            $stock = new StockLevel;
            $stock->product_variant_id = $variant->id;
            $stock->physical_quantity = 0;
            $stock->save();
        }

        // 9. Réceptionner 10 unités.
        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $cashier->id,
            reason: 'Stock initial du test POS'
        );

        $this->assertSame(
            10,
            (int) $stock->fresh()->physical_quantity
        );

        // 10. Créer une véritable vente POS.
        $order = app(CreatePosOrder::class)->execute(
            sessionId: $session->id,
            customerName: 'Client comptoir',
            customerPhone: '+237690000001',
            customerEmail: null,
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
            actor: $cashier
        );

        // 11. Vérifier la commande.
        $this->assertSame('PENDING', $order->status);
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame('PICKUP', $order->fulfillment_type);

        $this->assertSame(
            '24000.00',
            (string) $order->total
        );

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $store->id,
            'pos_session_id' => $session->id,
            'payment_status' => 'UNPAID',
        ]);

        // 12. Vérifier l'article vendu.
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        // 13. Vérifier la réservation de stock.
        $this->assertDatabaseHas('stock_reservations', [
            'reference_type' => 'ORDER',
            'reference_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'status' => 'ACTIVE',
        ]);

        // 14. Le stock physique reste inchangé.
        // La vente n'est pas encore payée.
        $this->assertSame(
            10,
            (int) $stock->fresh()->physical_quantity
        );

        // 15. Aucun paiement créé artificiellement.
        $this->assertDatabaseCount('payments', 0);

        // 16. La session reste ouverte.
        $this->assertSame(
            'OPEN',
            $session->fresh()->status
        );

        // 17. Créer un paiement POS en espèces.
        $payment = app(CreatePosCashPayment::class)->execute(
            sessionId: $session->id,
            orderId: $order->id,
            amount: '24000.00',
            cashReceived: '25000.00',
            actor: $cashier
        );

        $this->assertSame('PENDING', $payment->status);
        $this->assertSame('1000.00', $payment->change_given);
        $this->assertSame(
            $session->id,
            $payment->pos_session_id
        );

        // Le paiement en attente ne modifie pas le stock.
        $this->assertSame(
            10,
            (int) $stock->fresh()->physical_quantity
        );

        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        // 18. Confirmer le paiement CASH.
        $confirmed = app(ConfirmPayment::class)->execute(
            paymentId: $payment->id,
            userId: $cashier->id
        );

        $this->assertSame('CONFIRMED', $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);

        $this->assertSame(
            $cashier->id,
            $confirmed->received_by
        );

        // 19. La commande devient entièrement payée.
        $this->assertSame(
            'PAID',
            $order->fresh()->payment_status
        );

        // 20. Le stock est maintenant converti en vente.
        $this->assertSame(
            8,
            (int) $stock->fresh()->physical_quantity
        );

        // 21. Les associations POS sont conservées.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'store_id' => $store->id,
            'order_id' => $order->id,
            'pos_session_id' => $session->id,
            'method' => 'CASH',
            'status' => 'CONFIRMED',
        ]);

        // 22. La confirmation ne ferme pas la session.
        $this->assertSame(
            'OPEN',
            $session->fresh()->status
        );

        // Aucun bilan financier de clôture n'est
        // encore calculé artificiellement.
        $this->assertNull(
            $session->fresh()->closing_cash_expected
        );
    }
}
