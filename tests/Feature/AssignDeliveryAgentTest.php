<?php

namespace Tests\Feature;


use App\Actions\Stock\ConvertStockReservation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Actions\Deliveries\ConfirmDeliveryReturn;
use App\Actions\Stock\CreateStockReservation;
use App\Actions\Stock\ExpireStockReservation;
use App\Actions\Stock\ReleaseStockReservation;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ExpireOrder;
use App\Actions\Deliveries\UpdateDeliveryStatus;
use App\Actions\Deliveries\AssignDeliveryAgent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssignDeliveryAgentTest extends TestCase
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

    private function prepareStore(string $slug): array
    {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        $owner = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $agent = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $owner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $agent->roles()->attach(
            Role::where('code', 'DELIVERY_AGENT')
                ->firstOrFail()->id
        );

        $this->actingAs($owner);
        app(TenantContext::class)->setFromUser($owner);

        $customer = Customer::create([
            'name' => 'Jean Client',
            'phone' => '69000000' . $store->id,
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-DEL-' . $store->id,
            'status' => 'PENDING',
            'payment_status' => 'UNPAID',
            'fulfillment_type' => 'DELIVERY',
            'customer_name' => 'Jean Client',
            'customer_phone' => $customer->phone,
            'delivery_address' => 'Douala',
            'subtotal' => '10000.00',
            'delivery_fee' => '1000.00',
            'total' => '11000.00',
            'reservation_expires_at' => now()->addHour(),
        ]);

        return [$order, $owner, $agent];
    }

    private function assign(Order $order, User $agent)
    {
        return app(AssignDeliveryAgent::class)->execute(
            orderId: $order->id,
            deliveryAgentId: $agent->id
        );
    }

    private function assertRejected(
        callable $action,
        string $field
    ): void {
        try {
            $action();
            $this->fail('Affectation interdite attendue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }

    public function test_owner_can_assign_delivery_agent(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $delivery = $this->assign($order, $agent);

        $this->assertSame('ASSIGNED', $delivery->status);
        $this->assertSame($agent->id, $delivery->assigned_to);
        $this->assertSame($order->store_id, $delivery->store_id);
        $this->assertNotNull($delivery->assigned_at);

        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_agent_from_another_store_is_rejected(): void
    {
        [$orderA, $ownerA] = $this->prepareStore('alpha');
        [$orderB, $ownerB, $agentB] =
            $this->prepareStore('beta');

        $this->actingAs($ownerA);
        app(TenantContext::class)->setFromUser($ownerA);

        $this->assertRejected(
            fn() => $this->assign($orderA, $agentB),
            'assigned_to'
        );

        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_delivery_agent_cannot_assign_himself(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $this->expectException(
            AuthorizationException::class
        );

        $this->assign($order, $agent);
    }

    public function test_pickup_order_cannot_be_assigned(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $order->update([
            'fulfillment_type' => 'PICKUP',
        ]);

        $this->assertRejected(
            fn() => $this->assign($order, $agent),
            'order'
        );

        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_expired_unpaid_order_is_rejected(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $order->update([
            'reservation_expires_at' => now()->subMinute(),
        ]);

        $this->assertRejected(
            fn() => $this->assign($order, $agent),
            'order'
        );

        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_order_cannot_have_two_deliveries(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $this->assign($order, $agent);

        $this->assertRejected(
            fn() => $this->assign($order, $agent),
            'delivery'
        );

        $this->assertDatabaseCount('deliveries', 1);
    }


    public function test_paid_delivery_can_be_completed(): void
    {
        [$order, $owner, $agent] = $this->prepareStore('alpha');

        $order->update(['payment_status' => 'PAID']);

        $delivery = $this->assign($order, $agent);

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $pickedUp = app(UpdateDeliveryStatus::class)->execute(
            $delivery->id,
            'PICKED_UP'
        );

        $this->assertSame('PICKED_UP', $pickedUp->status);
        $this->assertNotNull($pickedUp->picked_up_at);

        $delivered = app(UpdateDeliveryStatus::class)->execute(
            $delivery->id,
            'DELIVERED'
        );

        $this->assertSame('DELIVERED', $delivered->status);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_cannot_deliver_without_pickup(): void
    {
        [$order, $owner, $agent] = $this->prepareStore('alpha');

        $order->update(['payment_status' => 'PAID']);

        $delivery = $this->assign($order, $agent);

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $this->assertRejected(
            fn() => app(UpdateDeliveryStatus::class)->execute(
                $delivery->id,
                'DELIVERED'
            ),
            'status'
        );

        $this->assertSame('ASSIGNED', $delivery->fresh()->status);
    }

    public function test_unpaid_delivery_without_stock_reservation_cannot_be_picked_up(): void
    {
        [$order, $owner, $agent] = $this->prepareStore('alpha');

        $delivery = $this->assign($order, $agent);

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $this->assertRejected(
            fn() => app(UpdateDeliveryStatus::class)->execute(
                $delivery->id,
                'PICKED_UP'
            ),
            'order'
        );

        $this->assertSame('ASSIGNED', $delivery->fresh()->status);
    }

    public function test_owner_cannot_update_delivery_as_agent(): void
    {
        [$order, $owner, $agent] = $this->prepareStore('alpha');

        $order->update(['payment_status' => 'PAID']);

        $delivery = $this->assign($order, $agent);

        $this->expectException(AuthorizationException::class);

        app(UpdateDeliveryStatus::class)->execute(
            $delivery->id,
            'PICKED_UP'
        );
    }

    public function test_failed_delivery_requires_reason_and_cannot_restart(): void
    {
        [$order, $owner, $agent] = $this->prepareStore('alpha');

        $delivery = $this->assign($order, $agent);

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $this->assertRejected(
            fn() => app(UpdateDeliveryStatus::class)->execute(
                $delivery->id,
                'FAILED'
            ),
            'failure_reason'
        );

        $failed = app(UpdateDeliveryStatus::class)->execute(
            $delivery->id,
            'FAILED',
            'Client injoignable'
        );

        $this->assertSame('FAILED', $failed->status);
        $this->assertSame(
            'Client injoignable',
            $failed->failure_reason
        );
        $this->assertNotNull($failed->failed_at);

        $this->assertRejected(
            fn() => app(UpdateDeliveryStatus::class)->execute(
                $delivery->id,
                'PICKED_UP'
            ),
            'status'
        );
    }


    public function test_guest_cannot_access_deliveries(): void
    {
        $this->getJson(route('deliveries.index'))
            ->assertUnauthorized();
    }

    public function test_owner_only_sees_deliveries_from_own_store(): void
    {
        [$orderA, $ownerA, $agentA] =
            $this->prepareStore('alpha');

        $deliveryA = $this->assign($orderA, $agentA);

        [$orderB, $ownerB, $agentB] =
            $this->prepareStore('beta');

        $deliveryB = $this->assign($orderB, $agentB);

        $this->actingAs($ownerA);
        app(TenantContext::class)->setFromUser($ownerA);

        $this->getJson(route('deliveries.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deliveryA->id);

        $this->getJson(route('deliveries.show', [
            'deliveryId' => $deliveryB->id,
        ]))->assertNotFound();
    }

    public function test_user_without_delivery_permission_is_forbidden(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->getJson(route('deliveries.index'))
            ->assertForbidden();
    }

    public function test_agent_cannot_access_another_agents_delivery(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $delivery = $this->assign($order, $agent);

        $otherAgent = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $otherAgent->roles()->attach(
            Role::where('code', 'DELIVERY_AGENT')
                ->firstOrFail()->id
        );

        $this->actingAs($otherAgent);
        app(TenantContext::class)->setFromUser($otherAgent);

        $this->getJson(route('deliveries.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(route('deliveries.show', [
            'deliveryId' => $delivery->id,
        ]))->assertNotFound();

        $this->patchJson(route('deliveries.update-status', [
            'deliveryId' => $delivery->id,
        ]), [
            'status' => 'PICKED_UP',
        ])->assertForbidden();

        $this->assertSame(
            'ASSIGNED',
            $delivery->fresh()->status
        );
    }

    public function test_owner_can_assign_agent_through_http(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $this->postJson(route('deliveries.assign', [
            'orderId' => $order->id,
        ]), [
            'assigned_to' => $agent->id,
        ])
            ->assertCreated()
            ->assertJsonPath('delivery.assigned_to', $agent->id)
            ->assertJsonPath('delivery.status', 'ASSIGNED');

        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_agent_can_complete_paid_delivery_through_http(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $order->update([
            'payment_status' => 'PAID',
        ]);

        $delivery = $this->assign($order, $agent);

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $this->patchJson(route('deliveries.update-status', [
            'deliveryId' => $delivery->id,
        ]), [
            'status' => 'PICKED_UP',
        ])
            ->assertOk()
            ->assertJsonPath('delivery.status', 'PICKED_UP');

        $this->patchJson(route('deliveries.update-status', [
            'deliveryId' => $delivery->id,
        ]), [
            'status' => 'DELIVERED',
        ])
            ->assertOk()
            ->assertJsonPath('delivery.status', 'DELIVERED');

        $this->assertNotNull(
            $delivery->fresh()->delivered_at
        );
    }


    public function test_cannot_cancel_order_after_delivery_pickup(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $delivery = $this->assign($order, $agent);

        // Simuler une commande dont le colis
        // a déjà quitté la boutique.
        $delivery->update([
            'status' => 'PICKED_UP',
            'picked_up_at' => now(),
        ]);

        $this->assertRejected(
            fn() => app(CancelOrder::class)->execute($order->id),
            'delivery'
        );

        $this->assertSame(
            'PENDING',
            $order->fresh()->status
        );
    }

    public function test_cannot_expire_order_after_delivery_pickup(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('alpha');

        $delivery = $this->assign($order, $agent);

        $delivery->update([
            'status' => 'PICKED_UP',
            'picked_up_at' => now(),
        ]);

        // Simuler une réservation arrivée à expiration.
        $order->update([
            'reservation_expires_at' => now()->subMinute(),
        ]);

        $this->assertRejected(
            fn() => app(ExpireOrder::class)->execute($order->id),
            'delivery'
        );

        $this->assertSame(
            'PENDING',
            $order->fresh()->status
        );
    }


    private function preparePickedUpReservedOrder(
        string $slug
    ): array {
        [$order, $owner, $agent] =
            $this->prepareStore($slug);

        // Créer un produit réel dans la boutique.
        $category = Category::create([
            'name' => 'Catégorie ' . $slug,
            'slug' => 'categorie-' . $slug,
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit ' . $slug,
            'slug' => 'produit-' . $slug,
            'status' => 'ACTIVE',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'DELIVERY-' . strtoupper($slug),
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        $stock = StockLevel::create([
            'product_variant_id' => $variant->id,
            'physical_quantity' => 10,
            'reserved_quantity' => 0,
            'low_stock_threshold' => 5,
        ]);


        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => 'Produit ' . $slug,
            'sku' => $variant->sku,
            'unit_price' => '5000.00',
            'discount_percentage' => '0.00',
            'discount_amount' => '0.00',
            'final_unit_price' => '5000.00',
            'quantity' => 2,
            'line_total' => '10000.00',
        ]);


        // Utiliser la vraie action de réservation ZEMBOA.
        $reservation = app(
            CreateStockReservation::class
        )->execute(
            productVariantId: $variant->id,
            quantity: 2,
            expiresAt: now()->addHour(),
            referenceType: 'ORDER',
            referenceId: $order->id
        );

        // Affecter le livreur.
        $delivery = $this->assign($order, $agent);

        // Simuler un colis déjà récupéré.
        $delivery->update([
            'status' => 'PICKED_UP',
            'picked_up_at' => now(),
        ]);

        // Simuler ensuite l'expiration de la réservation.
        $reservation->update([
            'expires_at' => now()->subMinute(),
        ]);

        $order->update([
            'reservation_expires_at' => now()->subMinute(),
        ]);

        return [$order, $reservation, $stock];
    }


    public function test_picked_up_delivery_stock_cannot_expire(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('alpha');

        $this->assertSame(
            2,
            $stock->fresh()->reserved_quantity
        );

        $this->assertRejected(
            fn() => app(ExpireStockReservation::class)
                ->execute($reservation->id),
            'delivery'
        );

        // La réservation reste active.
        $this->assertSame(
            'ACTIVE',
            $reservation->fresh()->status
        );

        // Les deux articles restent réservés.
        $this->assertSame(
            2,
            $stock->fresh()->reserved_quantity
        );

        // La commande n'a pas été annulée.
        $this->assertSame(
            'PENDING',
            $order->fresh()->status
        );
    }

    public function test_failed_delivery_after_pickup_cannot_release_stock(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('beta');

        // Le livreur a récupéré le colis,
        // mais la livraison échoue ensuite.
        $order->delivery()->firstOrFail()->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $this->assertRejected(
            fn() => app(ReleaseStockReservation::class)
                ->execute($reservation->id),
            'delivery'
        );

        // Aucun article n'est remis en vente.
        $this->assertSame(
            'ACTIVE',
            $reservation->fresh()->status
        );

        $this->assertSame(
            2,
            $stock->fresh()->reserved_quantity
        );

        $this->assertSame(
            10,
            $stock->fresh()->physical_quantity
        );
    }


    public function test_owner_can_confirm_unpaid_delivery_return(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('alpha');

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $returned = app(ConfirmDeliveryReturn::class)->execute(
            $delivery->id,
            'Colis reçu intact'
        );

        $this->assertNotNull($returned->returned_at);

        $this->assertSame(
            auth()->id(),
            $returned->return_confirmed_by
        );

        $this->assertSame(
            'Colis reçu intact',
            $returned->return_notes
        );

        $this->assertSame(
            'RELEASED',
            $reservation->fresh()->status
        );

        $this->assertSame(
            0,
            $stock->fresh()->reserved_quantity
        );

        $this->assertSame(
            10,
            $stock->fresh()->physical_quantity
        );

        $this->assertSame(
            'CANCELLED',
            $order->fresh()->status
        );
    }

    public function test_delivery_return_cannot_be_confirmed_twice(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('beta');

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $action = app(ConfirmDeliveryReturn::class);

        $action->execute($delivery->id);

        try {
            $action->execute($delivery->id);

            $this->fail(
                'Une deuxième confirmation doit être refusée.'
            );
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame(
            'RELEASED',
            $reservation->fresh()->status
        );

        $this->assertSame(
            0,
            $stock->fresh()->reserved_quantity
        );

        $this->assertSame(
            10,
            $stock->fresh()->physical_quantity
        );
    }


    public function test_delivery_agent_cannot_confirm_return(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('agent-return');

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $agent = $delivery->deliveryAgent()->firstOrFail();

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $this->expectException(AuthorizationException::class);

        app(ConfirmDeliveryReturn::class)->execute(
            $delivery->id
        );
    }

    public function test_other_store_owner_cannot_confirm_return(): void
    {
        [$orderA, $reservationA, $stockA] =
            $this->preparePickedUpReservedOrder('alpha-return');

        $deliveryA = $orderA->delivery()->firstOrFail();

        $deliveryA->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        [$orderB, $ownerB, $agentB] =
            $this->prepareStore('beta-return');

        $this->actingAs($ownerB);
        app(TenantContext::class)->setFromUser($ownerB);

        $this->expectException(ModelNotFoundException::class);

        app(ConfirmDeliveryReturn::class)->execute(
            $deliveryA->id
        );
    }

    public function test_partially_paid_return_preserves_stock(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('partial-return');

        // Simuler l'état financier pour isoler
        // le comportement de l'action de retour.
        $order->update([
            'payment_status' => 'PARTIALLY_PAID',
        ]);

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $returned = app(ConfirmDeliveryReturn::class)
            ->execute($delivery->id, 'Colis reçu');

        $this->assertNotNull($returned->returned_at);

        $this->assertSame(
            'ACTIVE',
            $reservation->fresh()->status
        );

        $this->assertSame(
            2,
            $stock->fresh()->reserved_quantity
        );

        $this->assertSame(
            10,
            $stock->fresh()->physical_quantity
        );

        $this->assertSame(
            'PARTIALLY_PAID',
            $order->fresh()->payment_status
        );

        $this->assertSame('PENDING', $order->fresh()->status);
    }

    public function test_fully_paid_return_does_not_double_count_stock(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('paid-return');

        // Simuler la conversion déjà effectuée
        // lors du paiement intégral.
        app(ConvertStockReservation::class)->execute(
            $reservation->id
        );

        $order->update([
            'payment_status' => 'PAID',
        ]);

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $this->assertSame(
            8,
            $stock->fresh()->physical_quantity
        );

        $returned = app(ConfirmDeliveryReturn::class)
            ->execute($delivery->id, 'Colis reçu intact');

        $this->assertNotNull($returned->returned_at);

        // Aucune deuxième conversion ou restitution
        // automatique du stock.
        $this->assertSame(
            'CONVERTED',
            $reservation->fresh()->status
        );

        $this->assertSame(
            0,
            $stock->fresh()->reserved_quantity
        );

        $this->assertSame(
            8,
            $stock->fresh()->physical_quantity
        );

        $this->assertSame(
            'PAID',
            $order->fresh()->payment_status
        );
    }


    public function test_owner_can_confirm_delivery_return_through_http(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('http-return-a');

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $this->patchJson(route('deliveries.confirm-return', [
            'deliveryId' => $delivery->id,
        ]), [
            'return_notes' => 'Colis récupéré intact',
        ])
            ->assertOk()
            ->assertJsonPath(
                'delivery.return_notes',
                'Colis récupéré intact'
            );

        $this->assertNotNull($delivery->fresh()->returned_at);
        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->assertSame('RELEASED', $reservation->fresh()->status);
        $this->assertSame(0, $stock->fresh()->reserved_quantity);
        $this->assertSame(10, $stock->fresh()->physical_quantity);
    }

    public function test_delivery_agent_cannot_confirm_return_through_http(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('http-return-b');

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $agent = $delivery->deliveryAgent()->firstOrFail();

        $this->actingAs($agent);
        app(TenantContext::class)->setFromUser($agent);

        $this->patchJson(route('deliveries.confirm-return', [
            'deliveryId' => $delivery->id,
        ]), [
            'return_notes' => 'Retour',
        ])->assertForbidden();

        $this->assertNull($delivery->fresh()->returned_at);
        $this->assertSame(2, $stock->fresh()->reserved_quantity);
    }

    public function test_other_store_cannot_confirm_return_through_http(): void
    {
        [$orderA] =
            $this->preparePickedUpReservedOrder('http-return-c');

        $deliveryA = $orderA->delivery()->firstOrFail();

        $deliveryA->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        [$orderB, $ownerB] =
            $this->prepareStore('http-return-other');

        $this->actingAs($ownerB);
        app(TenantContext::class)->setFromUser($ownerB);

        $this->patchJson(route('deliveries.confirm-return', [
            'deliveryId' => $deliveryA->id,
        ]), [
            'return_notes' => 'Tentative interdite',
        ])->assertNotFound();

        $this->assertNull($deliveryA->fresh()->returned_at);
    }

    public function test_return_cannot_be_confirmed_twice_through_http(): void
    {
        [$order, $reservation, $stock] =
            $this->preparePickedUpReservedOrder('http-return-d');

        $delivery = $order->delivery()->firstOrFail();

        $delivery->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'failure_reason' => 'Client absent',
        ]);

        $url = route('deliveries.confirm-return', [
            'deliveryId' => $delivery->id,
        ]);

        $this->patchJson($url, [
            'return_notes' => 'Premier retour',
        ])->assertOk();

        $this->patchJson($url, [
            'return_notes' => 'Deuxième retour',
        ])->assertUnprocessable();

        $this->assertSame('RELEASED', $reservation->fresh()->status);
        $this->assertSame(0, $stock->fresh()->reserved_quantity);
        $this->assertSame(10, $stock->fresh()->physical_quantity);
    }

    /*
|--------------------------------------------------------------------------
| Audit STORE_MANAGER - ZEMBOA
|--------------------------------------------------------------------------
*/

    private function authenticateStoreManager(User $owner): User
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $manager = User::factory()->create([
            'store_id' => $owner->store_id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $manager->roles()->attach($role->id);

        $this->actingAs($manager);

        app(TenantContext::class)->setFromUser($manager);

        return $manager;
    }

    // 1. Le gestionnaire peut affecter un livreur.
    public function test_manager_can_assign_delivery_agent_http(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('manager-assign');

        $this->authenticateStoreManager($owner);

        $this->postJson(route('deliveries.assign', [
            'orderId' => $order->id,
        ]), [
            'assigned_to' => $agent->id,
        ])
            ->assertCreated()
            ->assertJsonPath('delivery.assigned_to', $agent->id)
            ->assertJsonPath('delivery.status', 'ASSIGNED');

        $this->assertDatabaseCount('deliveries', 1);
    }

    // 2. Le gestionnaire voit sa boutique, pas les autres.
    public function test_manager_delivery_visibility_is_tenant_scoped(): void
    {
        [$orderA, $ownerA, $agentA] =
            $this->prepareStore('manager-alpha');

        $deliveryA = $this->assign($orderA, $agentA);

        [$orderB, $ownerB, $agentB] =
            $this->prepareStore('manager-beta');

        $deliveryB = $this->assign($orderB, $agentB);

        $this->authenticateStoreManager($ownerA);

        $this->getJson(route('deliveries.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deliveryA->id);

        $this->getJson(route('deliveries.show', [
            'deliveryId' => $deliveryA->id,
        ]))
            ->assertOk()
            ->assertJsonPath('delivery.id', $deliveryA->id);

        $this->getJson(route('deliveries.show', [
            'deliveryId' => $deliveryB->id,
        ]))->assertNotFound();
    }

    // 3. Le gestionnaire ne remplace pas le livreur
    // et ne confirme pas les retours physiques.
    public function test_manager_cannot_perform_restricted_delivery_actions(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('manager-restricted');

        $delivery = $this->assign($order, $agent);

        $this->authenticateStoreManager($owner);

        $this->patchJson(route('deliveries.update-status', [
            'deliveryId' => $delivery->id,
        ]), [
            'status' => 'PICKED_UP',
        ])->assertForbidden();

        $this->patchJson(route('deliveries.confirm-return', [
            'deliveryId' => $delivery->id,
        ]), [
            'return_notes' => 'Tentative gestionnaire',
        ])->assertForbidden();

        $this->assertSame(
            'ASSIGNED',
            $delivery->fresh()->status
        );

        $this->assertNull($delivery->fresh()->returned_at);
    }

    // 4. Un livreur inactif ne peut pas être affecté.
    public function test_manager_cannot_assign_inactive_agent(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('manager-inactive-agent');

        $agent->status = 'INACTIVE';
        $agent->save();

        $this->authenticateStoreManager($owner);

        $this->postJson(route('deliveries.assign', [
            'orderId' => $order->id,
        ]), [
            'assigned_to' => $agent->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');

        $this->assertDatabaseCount('deliveries', 0);
    }

    // 5. Le gestionnaire ne peut pas affecter
    // un livreur provenant d'une autre boutique.
    public function test_manager_cannot_assign_foreign_store_agent(): void
    {
        [$orderA, $ownerA] =
            $this->prepareStore('manager-store-a');

        [$orderB, $ownerB, $agentB] =
            $this->prepareStore('manager-store-b');

        $this->authenticateStoreManager($ownerA);

        $this->postJson(route('deliveries.assign', [
            'orderId' => $orderA->id,
        ]), [
            'assigned_to' => $agentB->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');

        $this->assertDatabaseCount('deliveries', 0);
    }

    // 6. Une caissière ne peut pas superviser
    // ni affecter les livraisons.
    public function test_cashier_cannot_manage_deliveries(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('cashier-delivery-denied');

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $cashier = User::factory()->create([
            'store_id' => $owner->store_id,
            'status' => 'ACTIVE',
        ]);

        $cashierRole = Role::where('code', 'CASHIER')
            ->firstOrFail();

        $cashier->roles()->attach($cashierRole->id);

        $this->actingAs($cashier);

        app(TenantContext::class)->setFromUser($cashier);

        $this->getJson(route('deliveries.index'))
            ->assertForbidden();

        $this->postJson(route('deliveries.assign', [
            'orderId' => $order->id,
        ]), [
            'assigned_to' => $agent->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('deliveries', 0);
    }


    public function test_invalid_delivery_transition_is_translated_in_both_languages(): void
    {
        [$order, $owner, $agent] =
            $this->prepareStore('i18n-delivery');

        // Le propriétaire affecte le livreur.
        $delivery = $this->assign($order, $agent);

        // Se connecter maintenant comme le livreur affecté.
        $this->actingAs($agent);

        // Activer le contexte de sa boutique.
        app(TenantContext::class)->setFromUser($agent);

        $this->assertSame(
            'ASSIGNED',
            $delivery->fresh()->status
        );

        $originalLocale = app()->getLocale();

        try {
            $messages = [
                'fr' => 'Transition de livraison non autorisée.',
                'en' => 'This delivery status transition is not allowed.',
            ];

            foreach ($messages as $locale => $expectedMessage) {
                app()->setLocale($locale);

                try {
                    // ASSIGNED -> DELIVERED est interdit.
                    app(UpdateDeliveryStatus::class)->execute(
                        $delivery->id,
                        'DELIVERED'
                    );

                    $this->fail(
                        'La transition interdite devait être refusée.'
                    );
                } catch (ValidationException $exception) {
                    $this->assertSame(
                        [$expectedMessage],
                        $exception->errors()['status'] ?? []
                    );
                }

                // Une erreur ne doit jamais modifier le statut.
                $this->assertSame(
                    'ASSIGNED',
                    $delivery->fresh()->status
                );
            }
        } finally {
            // Restaurer la langue initiale.
            app()->setLocale($originalLocale);
        }

        // La commande ne doit pas être marquée comme livrée.
        $this->assertSame(
            'PENDING',
            $order->fresh()->status
        );

        // Toujours une seule livraison.
        $this->assertDatabaseCount('deliveries', 1);
    }
}
