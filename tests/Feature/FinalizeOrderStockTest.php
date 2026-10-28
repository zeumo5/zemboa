<?php

namespace Tests\Feature;

use App\Actions\Orders\CreateOrder;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\FinalizeOrderPayment;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\CreateStockReservation;
use App\Actions\Stock\ReceiveStock;
use App\Actions\Stock\ReleaseStockReservation;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockReservation;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinalizeOrderStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_payment_is_rejected_when_reserved_quantity_is_insufficient(): void
    {
        // 1. Créer une boutique et un caissier.
        $store = Store::create([
            'name' => 'Boutique Quantites',
            'slug' => 'boutique-quantites',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);
        StoreSetting::create(['pickup_enabled' => true]);
        $this->actingAs($user);

        // 2. Autoriser le caissier à confirmer les paiements.
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

        // 3. Créer un produit à 2 000 FCFA.
        $category = Category::create([
            'name' => 'Accessoires',
            'slug' => 'accessoires-test-quantites',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Quantite Test',
            'slug' => 'produit-quantite-test',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'QUANTITE-TEST-001',
            'price' => '2000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // 4. Réceptionner 10 articles.
        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial de test'
        );

        // 5. Commander 5 articles.
        $order = app(CreateOrder::class)->execute(
            customerName: 'Jean Client',
            customerPhone: '690 000 001',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 5,
                ],
            ]
        );

        $this->assertSame('10000.00', $order->total);

        // Vérifier qu'une réservation correcte est acceptée.
        // CreateOrder a déjà réservé les 5 articles.

        DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            app(FinalizeOrderPayment::class)
                ->validateStockReservations($lockedOrder);
        });

        // Les 5 articles doivent toujours être réservés.
        $this->assertSame(
            5,
            StockReservation::query()
                ->where('reference_type', 'ORDER')
                ->where('reference_id', $order->id)
                ->where('status', 'ACTIVE')
                ->sum('quantity')
        );

        // 6. Retirer la réservation initiale de 5 articles.
        $originalReservation = StockReservation::query()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->firstOrFail();

        app(ReleaseStockReservation::class)->execute(
            $originalReservation->id
        );

        // 7. Simuler une réservation insuffisante de 3 articles.
        app(CreateStockReservation::class)->execute(
            productVariantId: $variant->id,
            quantity: 3,
            referenceType: 'ORDER',
            referenceId: $order->id
        );

        $stockBefore = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stockBefore->physical_quantity);
        $this->assertSame(3, $stockBefore->reserved_quantity);

        // Vérifier directement notre nouvelle méthode.
        // La commande doit être verrouillée en premier.
        try {
            DB::transaction(function () use ($order) {
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                app(FinalizeOrderPayment::class)
                    ->validateStockReservations($lockedOrder);
            });

            $this->fail(
                'La vérification aurait dû refuser les 3 articles réservés sur 5.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'stock',
                $exception->errors()
            );
        }

        // 8. Créer un paiement intégral de 10 000 FCFA.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '10000.00',
            'change_given' => '0.00',
        ]);

        // 9. La confirmation doit être refusée.
        try {
            app(ConfirmPayment::class)->execute(
                $payment->id,
                $user->id
            );

            $this->fail(
                'Le paiement aurait dû être refusé : seulement 3 articles réservés sur 5.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'stock',
                $exception->errors()
            );
        }

        // 10. Vérifier que la commande reste impayée.
        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        $this->assertSame(
            'PENDING',
            $payment->fresh()->status
        );

        $this->assertNull(
            $payment->fresh()->confirmed_at
        );

        // 11. Vérifier que le stock n'a pas diminué.
        $stockAfter = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stockAfter->physical_quantity);
        $this->assertSame(3, $stockAfter->reserved_quantity);

        // 12. Aucun mouvement SALE ne doit exister.
        $this->assertDatabaseMissing('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'SALE',
        ]);
    }
}
