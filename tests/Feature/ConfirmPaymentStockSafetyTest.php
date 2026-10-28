<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\ReceiveStock;
use App\Actions\Stock\ReleaseStockReservation;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockReservation;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfirmPaymentStockSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_payment_is_rejected_without_stock_reservations(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        // 2. Attribuer la permission de confirmer les paiements.
        $role = Role::firstOrCreate(
            ['code' => 'TEST_PAYMENT_CASHIER'],
            ['name' => 'Caissier de test']
        );

        $permission = Permission::firstOrCreate(
            ['code' => 'payments.confirm'],
            ['name' => 'Confirmer un paiement']
        );

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $user->roles()->syncWithoutDetaching([
            $role->id,
        ]);

        $this->actingAs($user);

        // 3. Créer un produit et sa variante.
        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Test',
            'slug' => 'produit-test',
            'status' => 'ACTIVE',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'STOCK-SAFETY-001',
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // 4. Créer une commande.
        $customer = Customer::create([
            'name' => 'Jean Client',
            'phone' => '+237690000001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-STOCK-SAFETY-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // 5. Ajouter deux unités à la commande.
        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => 'Produit Test',
            'sku' => 'STOCK-SAFETY-001',
            'unit_price' => '5000.00',
            'discount_amount' => '0.00',
            'final_unit_price' => '5000.00',
            'quantity' => 2,
            'line_total' => '10000.00',
        ]);

        // 6. Vérifier qu'aucune réservation n'existe.
        $this->assertDatabaseMissing('stock_reservations', [
            'reference_type' => 'ORDER',
            'reference_id' => $order->id,
        ]);

        // 7. Créer un paiement complet en attente.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '10000.00',
            'change_given' => '0.00',
        ]);

        // 8. La confirmation devrait être refusée.
        try {
            app(ConfirmPayment::class)->execute(
                $payment->id,
                $user->id
            );

            $this->fail(
                'Une commande sans réservation ne doit pas être marquée PAID.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'stock',
                $exception->errors()
            );
        }

        // 9. Vérifier que rien n'a été confirmé.
        $order->refresh();
        $payment->refresh();

        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame('PENDING', $payment->status);
        $this->assertNull($payment->confirmed_at);
    }

    public function test_full_payment_is_rejected_when_one_product_reservation_is_missing(): void
    {
        // 1. Créer une boutique et un caissier autorisé.
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);
        StoreSetting::create(['pickup_enabled' => true]);
        $this->actingAs($user);

        $role = Role::firstOrCreate(
            ['code' => 'TEST_PAYMENT_CASHIER'],
            ['name' => 'Caissier de test']
        );

        $permission = Permission::firstOrCreate(
            ['code' => 'payments.confirm'],
            ['name' => 'Confirmer un paiement']
        );

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $user->roles()->syncWithoutDetaching([
            $role->id,
        ]);

        // 2. Créer deux produits différents.
        $category = Category::create([
            'name' => 'Produits de test',
            'slug' => 'produits-de-test',
            'status' => 'ACTIVE',
        ]);

        $productA = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit A',
            'slug' => 'produit-a',
            'status' => 'ACTIVE',
        ]);

        $productB = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit B',
            'slug' => 'produit-b',
            'status' => 'ACTIVE',
        ]);

        $variantA = app(CreateProductVariant::class)->execute([
            'product_id' => $productA->id,
            'sku' => 'SAFETY-A-001',
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        $variantB = app(CreateProductVariant::class)->execute([
            'product_id' => $productB->id,
            'sku' => 'SAFETY-B-001',
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // 3. Ajouter le stock physique.
        foreach ([$variantA, $variantB] as $variant) {
            app(ReceiveStock::class)->execute(
                productVariantId: $variant->id,
                quantity: 10,
                userId: $user->id,
                reason: 'Stock initial pour test'
            );
        }

        // 4. Créer une commande avec deux produits.
        $order = app(CreateOrder::class)->execute(
            customerName: 'Jean Client',
            customerPhone: '690 000 001',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variantA->id,
                    'quantity' => 1,
                ],
                [
                    'product_variant_id' => $variantB->id,
                    'quantity' => 1,
                ],
            ],
        );

        $this->assertSame('10000.00', $order->total);

        // 5. Simuler la libération de la réservation B.
        $reservationB = StockReservation::query()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->where('product_variant_id', $variantB->id)
            ->firstOrFail();

        app(ReleaseStockReservation::class)->execute(
            $reservationB->id
        );

        $reservationB->refresh();

        $this->assertSame('RELEASED', $reservationB->status);

        // Une seule réservation reste active.
        $this->assertSame(
            1,
            StockReservation::query()
                ->where('reference_type', 'ORDER')
                ->where('reference_id', $order->id)
                ->where('status', 'ACTIVE')
                ->count()
        );

        // 6. Enregistrer le paiement complet.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '10000.00',
            'change_given' => '0.00',
        ]);

        // 7. La confirmation doit être refusée.
        try {
            app(ConfirmPayment::class)->execute(
                $payment->id,
                $user->id
            );

            $this->fail(
                'Une commande avec une réservation manquante ne doit pas être marquée PAID.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'stock',
                $exception->errors()
            );
        }

        // 8. Vérifier que rien n'a été vendu.
        $order->refresh();
        $payment->refresh();

        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame('PENDING', $payment->status);
        $this->assertNull($payment->confirmed_at);

        $stockA = $variantA->stockLevel()->firstOrFail();

        $this->assertSame(10, $stockA->physical_quantity);
        $this->assertSame(1, $stockA->reserved_quantity);

        // Aucun mouvement SALE ne doit avoir été créé.
        $this->assertDatabaseMissing('stock_movements', [
            'product_variant_id' => $variantA->id,
            'type' => 'SALE',
        ]);
    }
}
