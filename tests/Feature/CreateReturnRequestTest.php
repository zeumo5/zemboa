<?php

namespace Tests\Feature;



use App\Actions\Refunds\SimulateRefund;
use App\Actions\Refunds\ApproveRefund;
use App\Models\ReturnRequest;
use App\Actions\Refunds\PrepareRefund;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Actions\Returns\RestockReturnRequest;
use App\Actions\Stock\CreateStockReservation;
use App\Actions\Stock\ConvertStockReservation;
use App\Models\StockLevel;
use App\Actions\Returns\InspectReturnRequest;
use App\Actions\Returns\ReceiveReturnRequest;
use App\Actions\Returns\ReviewReturnRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Actions\Returns\CreateReturnRequest;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateReturnRequestTest extends TestCase
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

        // Garantir la présence des nouvelles permissions
        // puis les attribuer aux rôles existants.
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function prepareOrder(): array
    {
        $store = Store::create([
            'name' => 'Boutique Retours',
            'slug' => 'boutique-retours',
            'status' => 'ACTIVE',
        ]);

        $owner = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $owner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($owner);
        app(TenantContext::class)->setFromUser($owner);

        $this->assertTrue(
            $owner->hasRole('STORE_OWNER'),
            'ERREUR : le propriétaire ne possède pas le rôle STORE_OWNER.'
        );

        $this->assertTrue(
            $owner->hasPermission('returns.manage'),
            'ERREUR : la permission returns.manage manque au propriétaire.'
        );

        $this->assertSame(
            (int) $owner->store_id,
            (int) app(TenantContext::class)->storeId(),
            'ERREUR : la boutique active ne correspond pas au propriétaire.'
        );

        $settings = StoreSetting::query()
            ->where('store_id', $store->id)
            ->first();

        if ($settings === null) {
            $settings = StoreSetting::create([
                'returns_enabled' => true,
                'return_window_days' => 7,
            ]);
        } else {
            $settings->update([
                'returns_enabled' => true,
                'return_window_days' => 7,
            ]);
        }

        $customer = Customer::create([
            'name' => 'Client Test',
            'phone' => '690123456',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-RETURN-001',
            'status' => 'PENDING',
            'payment_status' => 'PAID',
            'fulfillment_type' => 'DELIVERY',
            'customer_name' => 'Client Test',
            'customer_phone' => '690123456',
            'delivery_address' => 'Douala',
            'subtotal' => '20000.00',
            'delivery_fee' => '1000.00',
            'total' => '21000.00',
        ]);


        $category = Category::create([
            'name' => 'Catégorie Retours',
            'slug' => 'categorie-retours',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Test',
            'slug' => 'produit-retour-test',
            'status' => 'ACTIVE',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'RETURN-001',
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);


        $orderItem = $order->items()->create([
            'product_name' => 'Produit Test',
            'sku' => 'RETURN-001',
            'unit_price' => '5000.00',
            'discount_percentage' => '0.00',
            'discount_amount' => '0.00',
            'final_unit_price' => '5000.00',
            'quantity' => 4,
            'line_total' => '20000.00',
            'product_variant_id' => $variant->id,
        ]);

        $delivery = Delivery::create([
            'order_id' => $order->id,
            'status' => 'DELIVERED',
            'delivered_at' => now()->subDay(),
        ]);

        return [
            $order,
            $orderItem,
            $delivery,
            $settings,
            $owner,
        ];
    }

    private function createReturn(
        Order $order,
        int $orderItemId,
        int $quantity
    ) {
        return app(CreateReturnRequest::class)->execute(
            orderId: $order->id,
            items: [
                [
                    'order_item_id' => $orderItemId,
                    'quantity' => $quantity,
                ],
            ],
            reason: 'Produit défectueux'
        );
    }

    private function assertRejected(
        callable $action,
        string $field
    ): void {
        try {
            $action();
            $this->fail('Un refus était attendu.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }

    public function test_owner_can_create_partial_return(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn(
            $order,
            $item->id,
            2
        );

        $this->assertSame('REQUESTED', $return->status);
        $this->assertSame($order->id, $return->order_id);
        $this->assertSame($order->store_id, $return->store_id);

        $this->assertSame(
            2,
            $return->items->first()->quantity
        );

        $this->assertDatabaseCount('return_requests', 1);
        $this->assertDatabaseCount('return_request_items', 1);
    }

    public function test_excessive_return_quantity_is_rejected(): void
    {
        [$order, $item] = $this->prepareOrder();

        $this->assertRejected(
            fn() => $this->createReturn(
                $order,
                $item->id,
                5
            ),
            'items'
        );

        $this->assertDatabaseCount('return_requests', 0);
    }

    public function test_previous_returns_count_towards_limit(): void
    {
        [$order, $item] = $this->prepareOrder();

        $this->createReturn($order, $item->id, 3);

        $this->assertRejected(
            fn() => $this->createReturn(
                $order,
                $item->id,
                2
            ),
            'items'
        );

        $this->assertDatabaseCount('return_requests', 1);
    }

    public function test_disabled_returns_are_rejected(): void
    {
        [$order, $item, $delivery, $settings] =
            $this->prepareOrder();

        $settings->update([
            'returns_enabled' => false,
        ]);

        $this->assertRejected(
            fn() => $this->createReturn(
                $order,
                $item->id,
                1
            ),
            'returns'
        );
    }

    public function test_expired_return_window_is_rejected(): void
    {
        [$order, $item, $delivery] =
            $this->prepareOrder();

        $delivery->update([
            'delivered_at' => now()->subDays(10),
        ]);

        $this->assertRejected(
            fn() => $this->createReturn(
                $order,
                $item->id,
                1
            ),
            'returns'
        );
    }

    public function test_user_without_permission_cannot_create_return(): void
    {
        [$order, $item, $delivery, $settings, $owner] =
            $this->prepareOrder();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->expectException(
            AuthorizationException::class
        );

        $this->createReturn($order, $item->id, 1);
    }


    public function test_other_store_cannot_create_return(): void
    {
        [$order, $item] = $this->prepareOrder();

        // Créer une deuxième boutique.
        $otherStore = Store::create([
            'name' => 'Boutique étrangère',
            'slug' => 'boutique-etrangere',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->expectException(ModelNotFoundException::class);

        $this->createReturn($order, $item->id, 1);
    }

    public function test_item_from_another_order_is_rejected(): void
    {
        [$order, $item] = $this->prepareOrder();

        // Créer une autre commande dans la même boutique.
        $otherOrder = $order->replicate();
        $otherOrder->order_number = 'ZM-RETURN-002';
        $otherOrder->save();

        // Cet article appartient à la deuxième commande.
        $otherItem = $item->replicate();
        $otherItem->order_id = $otherOrder->id;
        $otherItem->save();

        $this->assertRejected(
            fn() => $this->createReturn(
                $order,
                $otherItem->id,
                1
            ),
            'items'
        );

        $this->assertDatabaseCount('return_requests', 0);
    }


    public function test_owner_can_approve_return(): void
    {
        [$order, $item, $delivery, $settings, $owner] =
            $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 2);

        $reviewed = app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED',
            'Retour autorisé'
        );

        $this->assertSame('APPROVED', $reviewed->status);
        $this->assertSame($owner->id, $reviewed->reviewed_by);
        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertSame(
            'Retour autorisé',
            $reviewed->review_notes
        );
    }

    public function test_rejection_requires_reason(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        $this->assertRejected(
            fn() => app(ReviewReturnRequest::class)
                ->execute($return->id, 'REJECTED'),
            'review_notes'
        );

        $reviewed = app(ReviewReturnRequest::class)->execute(
            $return->id,
            'REJECTED',
            'Produit non éligible'
        );

        $this->assertSame('REJECTED', $reviewed->status);
        $this->assertSame(
            'Produit non éligible',
            $reviewed->review_notes
        );
    }

    public function test_return_cannot_be_reviewed_twice(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        $action = app(ReviewReturnRequest::class);

        $action->execute($return->id, 'APPROVED');

        $this->assertRejected(
            fn() => $action->execute(
                $return->id,
                'REJECTED',
                'Deuxième décision'
            ),
            'status'
        );

        $this->assertSame(
            'APPROVED',
            $return->fresh()->status
        );
    }

    public function test_user_without_permission_cannot_review_return(): void
    {
        [$order, $item, $delivery, $settings, $owner] =
            $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->expectException(
            AuthorizationException::class
        );

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );
    }

    public function test_other_store_cannot_review_return(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        $otherStore = Store::create([
            'name' => 'Autre boutique',
            'slug' => 'autre-boutique-review',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );
    }


    public function test_owner_can_receive_approved_return(): void
    {
        [$order, $item, $delivery, $settings, $owner] =
            $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 2);

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );

        $received = app(ReceiveReturnRequest::class)->execute(
            $return->id
        );

        $this->assertSame('RECEIVED', $received->status);
        $this->assertNotNull($received->received_at);
        $this->assertSame($owner->id, $received->received_by);

        // La réception ne modifie pas le paiement.
        $this->assertSame(
            'PAID',
            $order->fresh()->payment_status
        );
    }

    public function test_requested_return_cannot_be_received(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        $this->assertRejected(
            fn() => app(ReceiveReturnRequest::class)
                ->execute($return->id),
            'status'
        );

        $this->assertSame('REQUESTED', $return->fresh()->status);
        $this->assertNull($return->fresh()->received_at);
    }

    public function test_return_cannot_be_received_twice(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );

        $action = app(ReceiveReturnRequest::class);

        $action->execute($return->id);

        $this->assertRejected(
            fn() => $action->execute($return->id),
            'status'
        );

        $this->assertSame('RECEIVED', $return->fresh()->status);
    }

    public function test_user_without_permission_cannot_receive_return(): void
    {
        [$order, $item, $delivery, $settings, $owner] =
            $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->expectException(AuthorizationException::class);

        app(ReceiveReturnRequest::class)->execute($return->id);
    }

    public function test_other_store_cannot_receive_return(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 1);

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );

        $otherStore = Store::create([
            'name' => 'Boutique externe',
            'slug' => 'boutique-externe-receive',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        app(ReceiveReturnRequest::class)->execute($return->id);
    }


    private function prepareReceivedReturn(): array
    {
        [$order, $item, $delivery, $settings, $owner] =
            $this->prepareOrder();

        // Demande de retour de 3 articles.
        $return = $this->createReturn(
            $order,
            $item->id,
            3
        );

        // Approbation par le commerçant.
        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );

        // Réception physique des articles.
        app(ReceiveReturnRequest::class)->execute(
            $return->id
        );

        return [$return, $owner];
    }


    public function test_owner_can_inspect_received_return(): void
    {
        [$return, $owner] = $this->prepareReceivedReturn();

        $returnItem = $return->items()->firstOrFail();

        $inspected = app(InspectReturnRequest::class)->execute(
            $return->id,
            [
                [
                    'return_request_item_id' => $returnItem->id,
                    'restockable_quantity' => 2,
                    'inspection_notes' => 'Un article endommagé',
                ],
            ]
        );

        $this->assertSame('INSPECTED', $inspected->status);
        $this->assertNotNull($inspected->inspected_at);
        $this->assertSame($owner->id, $inspected->inspected_by);

        $this->assertSame(
            2,
            (int) $returnItem->fresh()->restockable_quantity
        );

        $this->assertSame(
            'Un article endommagé',
            $returnItem->fresh()->inspection_notes
        );

        // Aucun article remis en stock automatiquement.
        $this->assertNull($returnItem->fresh()->restocked_at);
    }

    public function test_return_must_be_received_before_inspection(): void
    {
        [$order, $item] = $this->prepareOrder();

        $return = $this->createReturn($order, $item->id, 2);

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );

        $returnItem = $return->items()->firstOrFail();

        $this->assertRejected(
            fn() => app(InspectReturnRequest::class)->execute(
                $return->id,
                [
                    [
                        'return_request_item_id' => $returnItem->id,
                        'restockable_quantity' => 2,
                    ],
                ]
            ),
            'status'
        );

        $this->assertSame('APPROVED', $return->fresh()->status);
    }

    public function test_excessive_restockable_quantity_is_rejected(): void
    {
        [$return] = $this->prepareReceivedReturn();

        $returnItem = $return->items()->firstOrFail();

        // Le client a retourné 3 articles.
        // Il est interdit d'en déclarer 4 revendables.
        $this->assertRejected(
            fn() => app(InspectReturnRequest::class)->execute(
                $return->id,
                [
                    [
                        'return_request_item_id' => $returnItem->id,
                        'restockable_quantity' => 4,
                    ],
                ]
            ),
            'restockable_quantity'
        );

        $this->assertSame('RECEIVED', $return->fresh()->status);
        $this->assertNull(
            $returnItem->fresh()->restockable_quantity
        );
    }

    public function test_return_cannot_be_inspected_twice(): void
    {
        [$return] = $this->prepareReceivedReturn();

        $returnItem = $return->items()->firstOrFail();

        $action = app(InspectReturnRequest::class);

        $items = [
            [
                'return_request_item_id' => $returnItem->id,
                'restockable_quantity' => 2,
            ],
        ];

        $action->execute($return->id, $items);

        $this->assertRejected(
            fn() => $action->execute($return->id, $items),
            'status'
        );

        $this->assertSame('INSPECTED', $return->fresh()->status);

        $this->assertSame(
            2,
            (int) $returnItem->fresh()->restockable_quantity
        );
    }

    public function test_user_without_permission_cannot_inspect_return(): void
    {
        [$return, $owner] = $this->prepareReceivedReturn();

        $returnItem = $return->items()->firstOrFail();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->expectException(AuthorizationException::class);

        app(InspectReturnRequest::class)->execute(
            $return->id,
            [
                [
                    'return_request_item_id' => $returnItem->id,
                    'restockable_quantity' => 2,
                ],
            ]
        );
    }

    public function test_other_store_cannot_inspect_return(): void
    {
        [$return] = $this->prepareReceivedReturn();

        $returnItem = $return->items()->firstOrFail();

        $otherStore = Store::create([
            'name' => 'Autre boutique inspection',
            'slug' => 'autre-boutique-inspection',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        app(InspectReturnRequest::class)->execute(
            $return->id,
            [
                [
                    'return_request_item_id' => $returnItem->id,
                    'restockable_quantity' => 2,
                ],
            ]
        );
    }


    private function prepareInspectedReturnForRestock(
        int $restockableQuantity = 2
    ): array {
        [$order, $item, $delivery, $settings, $owner] =
            $this->prepareOrder();

        // Stock initial : 10 articles.
        $stock = StockLevel::create([
            'product_variant_id' => $item->product_variant_id,
            'physical_quantity' => 10,
            'reserved_quantity' => 0,
            'low_stock_threshold' => 5,
        ]);

        // Réserver les 4 articles de la commande.
        $reservation = app(CreateStockReservation::class)
            ->execute(
                productVariantId: $item->product_variant_id,
                quantity: 4,
                expiresAt: now()->addDay(),
                referenceType: 'ORDER',
                referenceId: $order->id
            );

        // Simuler la conversion du stock lors du paiement.
        app(ConvertStockReservation::class)->execute(
            $reservation->id,
            $owner->id
        );

        // Le stock physique doit passer de 10 à 6.
        $this->assertSame(
            6,
            $stock->fresh()->physical_quantity
        );

        // Le client retourne 3 articles.
        $return = $this->createReturn(
            $order,
            $item->id,
            3
        );

        app(ReviewReturnRequest::class)->execute(
            $return->id,
            'APPROVED'
        );

        app(ReceiveReturnRequest::class)->execute(
            $return->id
        );

        $returnItem = $return->items()->firstOrFail();

        // Après inspection, seuls 2 articles sont revendables.
        app(InspectReturnRequest::class)->execute(
            $return->id,
            [
                [
                    'return_request_item_id' => $returnItem->id,
                    'restockable_quantity' => $restockableQuantity,
                    'inspection_notes' => 'Un article endommagé',
                ],
            ]
        );

        return [$return, $stock, $owner];
    }


    public function test_owner_can_restock_inspected_return(): void
    {
        [$return, $stock] =
            $this->prepareInspectedReturnForRestock();

        app(RestockReturnRequest::class)->execute($return->id);

        // 6 articles après la vente + 2 revendables.
        $this->assertSame(
            8,
            $stock->fresh()->physical_quantity
        );

        $this->assertSame(
            0,
            $stock->fresh()->reserved_quantity
        );

        $this->assertNotNull(
            $return->items()->firstOrFail()->fresh()->restocked_at
        );

        $this->assertSame(
            'INSPECTED',
            $return->fresh()->status
        );
    }

    public function test_return_cannot_be_restocked_twice(): void
    {
        [$return, $stock] =
            $this->prepareInspectedReturnForRestock();

        $action = app(RestockReturnRequest::class);

        $action->execute($return->id);

        $this->assertRejected(
            fn() => $action->execute($return->id),
            'stock'
        );

        // Le second appel ne doit ajouter aucun article.
        $this->assertSame(
            8,
            $stock->fresh()->physical_quantity
        );
    }

    public function test_user_without_permission_cannot_restock(): void
    {
        [$return, $stock, $owner] =
            $this->prepareInspectedReturnForRestock();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->expectException(
            AuthorizationException::class
        );

        app(RestockReturnRequest::class)->execute($return->id);
    }

    public function test_other_store_cannot_restock_return(): void
    {
        [$return, $stock] =
            $this->prepareInspectedReturnForRestock();

        $otherStore = Store::create([
            'name' => 'Boutique externe restock',
            'slug' => 'boutique-externe-restock',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        app(RestockReturnRequest::class)->execute($return->id);
    }


    public function test_restock_creates_exactly_one_return_movement(): void
    {
        [$return, $stock, $owner] =
            $this->prepareInspectedReturnForRestock();

        app(RestockReturnRequest::class)->execute($return->id);

        $movements = StockMovement::query()
            ->where('type', 'RETURN')
            ->get();

        // Exactement un mouvement RETURN.
        $this->assertCount(1, $movements);

        $movement = $movements->first();

        $this->assertSame(2, $movement->quantity);

        $this->assertSame(
            (int) $stock->product_variant_id,
            (int) $movement->product_variant_id
        );

        $this->assertSame(
            (int) $owner->id,
            (int) $movement->created_by
        );

        $this->assertStringContainsString(
            'Retour SAV #' . $return->id,
            $movement->reason
        );

        $this->assertSame(
            8,
            $stock->fresh()->physical_quantity
        );
    }

    public function test_zero_restockable_quantity_creates_no_stock_movement(): void
    {
        // Trois articles retournés,
        // aucun article revendable.
        [$return, $stock] =
            $this->prepareInspectedReturnForRestock(0);

        $this->assertSame(
            6,
            $stock->fresh()->physical_quantity
        );

        app(RestockReturnRequest::class)->execute($return->id);

        // Le stock physique reste inchangé.
        $this->assertSame(
            6,
            $stock->fresh()->physical_quantity
        );

        $this->assertSame(
            0,
            $stock->fresh()->reserved_quantity
        );

        // Aucun mouvement RETURN ne doit être créé.
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );

        // Le retour est marqué comme traité,
        // même sans article revendable.
        $returnItem = $return->items()->firstOrFail();

        $this->assertSame(
            0,
            $returnItem->fresh()->restockable_quantity
        );

        $this->assertNotNull(
            $returnItem->fresh()->restocked_at
        );

        // Une deuxième tentative doit être refusée.
        $this->assertRejected(
            fn() => app(RestockReturnRequest::class)
                ->execute($return->id),
            'stock'
        );
    }


    private function prepareRefundScenario(
        string $paymentStatus = 'CONFIRMED',
        string $paymentAmount = '21000.00'
    ): array {
        [$return, $stock, $owner] =
            $this->prepareInspectedReturnForRestock();

        $order = $return->order()->firstOrFail();

        // Paiement fictif enregistré uniquement
        // dans la base SQLite de test.
        $order->payments()->create([
            'amount' => $paymentAmount,
            'method' => 'CASH',
            'status' => $paymentStatus,
            'confirmed_at' => $paymentStatus === 'CONFIRMED'
                ? now()
                : null,
        ]);

        return [$return, $order, $stock, $owner];
    }


    public function test_owner_can_prepare_partial_refund(): void
    {
        [$return, $order, $stock] =
            $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        // 3 articles × 5000 FCFA.
        $this->assertSame('15000.00', $refund->amount);
        $this->assertSame('PENDING', $refund->status);
        $this->assertSame('CASH', $refund->method);
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame($return->id, $refund->return_request_id);

        $this->assertDatabaseCount('refunds', 1);

        // Aucun remboursement réel ni mouvement de stock.
        $this->assertNull($refund->simulated_at);
        $this->assertSame(
            6,
            $stock->fresh()->physical_quantity
        );
    }

    public function test_refund_uses_discounted_unit_price(): void
    {
        [$return] = $this->prepareRefundScenario();

        $orderItem = $return->items()
            ->firstOrFail()
            ->orderItem()
            ->firstOrFail();

        // Prix initial : 5000 FCFA.
        // Prix après réduction : 4500 FCFA.
        $orderItem->update([
            'final_unit_price' => '4500.00',
            'discount_percentage' => '10.00',
            'discount_amount' => '500.00',
            'line_total' => '18000.00',
        ]);

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        // 3 articles retournés × 4500 FCFA.
        $this->assertSame('13500.00', $refund->amount);
    }

    public function test_unconfirmed_payment_cannot_be_refunded(): void
    {
        [$return] = $this->prepareRefundScenario(
            'PENDING'
        );

        $this->assertRejected(
            fn() => app(PrepareRefund::class)
                ->execute($return->id),
            'payment'
        );

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_total_refunds_cannot_exceed_confirmed_payments(): void
    {
        [$return, $order] = $this->prepareRefundScenario();

        // Simuler un remboursement déjà engagé
        // pour une autre demande de cette commande.
        $previousReturn = \App\Models\ReturnRequest::create([
            'order_id' => $order->id,
            'reason' => 'Retour précédent',
        ]);

        Refund::create([
            'order_id' => $order->id,
            'return_request_id' => $previousReturn->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'reference' => 'RF-TEST-PREVIOUS',
        ]);

        // 10000 + 15000 > 21000 FCFA.
        $this->assertRejected(
            fn() => app(PrepareRefund::class)
                ->execute($return->id),
            'amount'
        );

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_refund_cannot_be_prepared_twice(): void
    {
        [$return] = $this->prepareRefundScenario();

        $action = app(PrepareRefund::class);

        $action->execute($return->id);

        $this->assertRejected(
            fn() => $action->execute($return->id),
            'refund'
        );

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_user_without_permission_cannot_prepare_refund(): void
    {
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->expectException(
            AuthorizationException::class
        );

        app(PrepareRefund::class)->execute($return->id);
    }


    public function test_owner_can_approve_prepared_refund(): void
    {
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        $approved = app(ApproveRefund::class)
            ->execute($refund->id);

        $this->assertSame('APPROVED', $approved->status);
        $this->assertSame($owner->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $this->assertSame('15000.00', $approved->amount);

        // Aucun remboursement réellement effectué.
        $this->assertNull($approved->simulated_at);

        $this->assertSame(
            6,
            $stock->fresh()->physical_quantity
        );
    }

    public function test_refund_cannot_be_approved_twice(): void
    {
        [$return] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        $action = app(ApproveRefund::class);

        $action->execute($refund->id);

        $this->assertRejected(
            fn() => $action->execute($refund->id),
            'status'
        );

        $this->assertSame(
            'APPROVED',
            $refund->fresh()->status
        );
    }

    public function test_user_without_permission_cannot_approve_refund(): void
    {
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->expectException(
            AuthorizationException::class
        );

        app(ApproveRefund::class)->execute($refund->id);
    }

    public function test_other_store_cannot_approve_refund(): void
    {
        [$return] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        $otherStore = Store::create([
            'name' => 'Boutique externe remboursements',
            'slug' => 'boutique-externe-refunds',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        app(ApproveRefund::class)->execute($refund->id);
    }

    public function test_approval_rejects_excessive_committed_refunds(): void
    {
        [$return, $order] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        // Ajouter un engagement financier supplémentaire
        // après la préparation du premier remboursement.
        $previousReturn = ReturnRequest::create([
            'order_id' => $order->id,
            'reason' => 'Autre retour',
        ]);

        Refund::create([
            'order_id' => $order->id,
            'return_request_id' => $previousReturn->id,
            'amount' => '8000.00',
            'method' => 'CASH',
            'reference' => 'RF-APPROVAL-LIMIT',
        ]);

        // 15000 + 8000 = 23000 FCFA.
        // Paiements confirmés : 21000 FCFA.
        $this->assertRejected(
            fn() => app(ApproveRefund::class)
                ->execute($refund->id),
            'amount'
        );

        $this->assertSame(
            'PENDING',
            $refund->fresh()->status
        );
    }

    public function test_refund_cannot_be_approved_without_confirmed_payment(): void
    {
        [$return, $order] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        // Simuler une perte de confirmation financière
        // avant l'approbation.
        $payment = $order->payments()->firstOrFail();

        $payment->update([
            'status' => 'PENDING',
        ]);

        $this->assertRejected(
            fn() => app(ApproveRefund::class)
                ->execute($refund->id),
            'amount'
        );

        $this->assertSame(
            'PENDING',
            $refund->fresh()->status
        );

        $this->assertNull($refund->fresh()->approved_at);
    }


    public function test_owner_can_simulate_approved_refund(): void
    {
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        app(ApproveRefund::class)->execute($refund->id);

        $simulated = app(SimulateRefund::class)
            ->execute($refund->id);

        $this->assertSame('SIMULATED', $simulated->status);
        $this->assertNotNull($simulated->approved_at);
        $this->assertNotNull($simulated->simulated_at);

        $this->assertSame('15000.00', $simulated->amount);
        $this->assertSame($owner->id, $simulated->approved_by);

        $this->assertDatabaseCount('refunds', 1);

        // La simulation ne modifie pas le stock.
        $this->assertSame(
            6,
            $stock->fresh()->physical_quantity
        );

        // Le paiement d'origine reste confirmé.
        $payment = $order->payments()->firstOrFail();

        $this->assertSame('CONFIRMED', $payment->fresh()->status);
        $this->assertSame('21000.00', $payment->fresh()->amount);
    }

    public function test_unapproved_refund_cannot_be_simulated(): void
    {
        [$return] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        $this->assertRejected(
            fn() => app(SimulateRefund::class)
                ->execute($refund->id),
            'status'
        );

        $this->assertSame('PENDING', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->simulated_at);
    }

    public function test_refund_cannot_be_simulated_twice(): void
    {
        [$return] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        app(ApproveRefund::class)->execute($refund->id);

        $action = app(SimulateRefund::class);

        $action->execute($refund->id);

        $firstSimulatedAt = $refund->fresh()->simulated_at;

        $this->assertRejected(
            fn() => $action->execute($refund->id),
            'status'
        );

        $this->assertSame(
            'SIMULATED',
            $refund->fresh()->status
        );

        $this->assertEquals(
            $firstSimulatedAt,
            $refund->fresh()->simulated_at
        );

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_simulation_rejects_modified_refund_amount(): void
    {
        [$return] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        app(ApproveRefund::class)->execute($refund->id);

        // Simuler une modification irrégulière
        // du montant après approbation.
        $refund->amount = '14000.00';
        $refund->save();

        $this->assertRejected(
            fn() => app(SimulateRefund::class)
                ->execute($refund->id),
            'amount'
        );

        $this->assertSame(
            'APPROVED',
            $refund->fresh()->status
        );

        $this->assertNull($refund->fresh()->simulated_at);
    }

    public function test_simulation_rejects_lost_payment_confirmation(): void
    {
        [$return, $order] = $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        app(ApproveRefund::class)->execute($refund->id);

        // La confirmation du paiement disparaît
        // avant la simulation.
        $payment = $order->payments()->firstOrFail();

        $payment->update([
            'status' => 'PENDING',
        ]);

        $this->assertRejected(
            fn() => app(SimulateRefund::class)
                ->execute($refund->id),
            'amount'
        );

        $this->assertSame(
            'APPROVED',
            $refund->fresh()->status
        );

        $this->assertNull($refund->fresh()->simulated_at);
    }

    public function test_user_without_permission_cannot_simulate_refund(): void
    {
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        $refund = app(PrepareRefund::class)
            ->execute($return->id);

        app(ApproveRefund::class)->execute($refund->id);

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        $this->expectException(
            AuthorizationException::class
        );

        app(SimulateRefund::class)->execute($refund->id);
    }


    public function test_owner_can_list_returns_over_http(): void
    {
        [$return] = $this->prepareRefundScenario();

        $response = $this->getJson(
            route('returns.index')
        );

        $response->assertOk();

        $response->assertJsonFragment([
            'id' => $return->id,
        ]);
    }

    public function test_owner_can_view_return_over_http(): void
    {
        [$return] = $this->prepareRefundScenario();

        $response = $this->getJson(
            route('returns.show', [
                'returnRequestId' => $return->id,
            ])
        );

        $response->assertOk();

        $response->assertJsonPath(
            'id',
            $return->id
        );

        $response->assertJsonPath(
            'status',
            'INSPECTED'
        );
    }

    public function test_owner_can_create_return_over_http(): void
    {
        [$return, $order] = $this->prepareRefundScenario();

        // Trois des quatre articles ont déjà
        // été retournés dans le scénario initial.
        // Il reste un article retournable.
        $orderItemId = $return->items()
            ->firstOrFail()
            ->order_item_id;

        $response = $this->postJson(
            route('returns.store'),
            [
                'order_id' => $order->id,
                'reason' => 'Dernier article défectueux',
                'items' => [
                    [
                        'order_item_id' => $orderItemId,
                        'quantity' => 1,
                    ],
                ],
            ]
        );

        $response->assertCreated();

        $response->assertJsonPath(
            'return.status',
            'REQUESTED'
        );

        $this->assertDatabaseCount(
            'return_requests',
            2
        );
    }


    public function test_guest_cannot_access_returns_over_http(): void
    {
        $this->getJson(route('returns.index'))
            ->assertUnauthorized();

        $this->postJson(route('returns.store'), [])
            ->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_manage_returns_over_http(): void
    {
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->getJson(route('returns.index'))
            ->assertForbidden();

        $this->getJson(route('returns.show', [
            'returnRequestId' => $return->id,
        ]))->assertForbidden();

        $this->postJson(route('returns.store'), [
            'order_id' => $order->id,
            'reason' => 'Retour non autorisé',
            'items' => [
                [
                    'order_item_id' => $return->items()
                        ->firstOrFail()->order_item_id,
                    'quantity' => 1,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_other_store_cannot_view_returns_over_http(): void
    {
        [$return] = $this->prepareRefundScenario();

        $otherStore = Store::create([
            'name' => 'Boutique HTTP externe',
            'slug' => 'boutique-http-externe',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->getJson(route('returns.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(route('returns.show', [
            'returnRequestId' => $return->id,
        ]))->assertNotFound();
    }

    public function test_other_store_cannot_create_return_over_http(): void
    {
        [$return, $order] = $this->prepareRefundScenario();

        $otherStore = Store::create([
            'name' => 'Boutique externe création',
            'slug' => 'boutique-externe-creation',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->postJson(route('returns.store'), [
            'order_id' => $order->id,
            'reason' => 'Tentative interboutique',
            'items' => [
                [
                    'order_item_id' => $return->items()
                        ->firstOrFail()->order_item_id,
                    'quantity' => 1,
                ],
            ],
        ])->assertNotFound();

        $this->assertDatabaseCount('return_requests', 1);
    }


    private function preparePendingReturnForReviewHttp(): array
    {
        [$existingReturn, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        // Trois articles sont déjà retournés.
        // Il en reste un sur les quatre achetés.
        $orderItemId = $existingReturn->items()
            ->firstOrFail()
            ->order_item_id;

        $response = $this->postJson(
            route('returns.store'),
            [
                'order_id' => $order->id,
                'reason' => 'Article défectueux',
                'items' => [
                    [
                        'order_item_id' => $orderItemId,
                        'quantity' => 1,
                    ],
                ],
            ]
        );

        $response->assertCreated();

        $returnId = (int) $response->json('return.id');

        return [$returnId, $order, $owner];
    }


    public function test_owner_can_approve_return_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            [
                'decision' => 'APPROVED',
                'review_notes' => 'Retour accepté',
            ]
        )
            ->assertOk()
            ->assertJsonPath('return.status', 'APPROVED');

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'APPROVED',
        ]);
    }

    public function test_owner_can_reject_return_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            [
                'decision' => 'REJECTED',
                'review_notes' => 'Conditions non respectées',
            ]
        )
            ->assertOk()
            ->assertJsonPath('return.status', 'REJECTED');

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'REJECTED',
        ]);
    }

    public function test_rejection_requires_notes_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            [
                'decision' => 'REJECTED',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'REQUESTED',
        ]);
    }

    public function test_return_cannot_be_reviewed_twice_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        $url = route('returns.review', [
            'returnRequestId' => $returnId,
        ]);

        $this->patchJson($url, [
            'decision' => 'APPROVED',
        ])->assertOk();

        $this->patchJson($url, [
            'decision' => 'REJECTED',
            'review_notes' => 'Deuxième décision',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'APPROVED',
        ]);
    }

    public function test_user_without_permission_cannot_review_return_over_http(): void
    {
        [$returnId, $order, $owner] =
            $this->preparePendingReturnForReviewHttp();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            [
                'decision' => 'APPROVED',
            ]
        )->assertForbidden();

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'REQUESTED',
        ]);
    }

    public function test_other_store_cannot_review_return_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        $otherStore = Store::create([
            'name' => 'Boutique externe review',
            'slug' => 'boutique-externe-review',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);

        app(TenantContext::class)->setFromUser($otherOwner);

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            [
                'decision' => 'APPROVED',
            ]
        )->assertNotFound();

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'REQUESTED',
        ]);
    }


    public function test_owner_can_receive_approved_return_over_http(): void
    {
        [$returnId, $order, $owner] =
            $this->preparePendingReturnForReviewHttp();

        $url = route('returns.receive', [
            'returnRequestId' => $returnId,
        ]);

        // Le commerçant approuve d'abord la demande.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        $response = $this->patchJson($url);

        $response->assertOk()
            ->assertJsonPath('return.status', 'RECEIVED')
            ->assertJsonPath('return.received_by', $owner->id);

        $this->assertNotNull(
            $response->json('return.received_at')
        );

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'RECEIVED',
            'received_by' => $owner->id,
        ]);

        // La réception ne modifie pas le paiement.
        $payment = $order->payments()->firstOrFail();

        $this->assertSame(
            'CONFIRMED',
            $payment->fresh()->status
        );

        // Aucun mouvement de retour en stock.
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );
    }

    public function test_requested_return_cannot_be_received_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        // Le retour est encore REQUESTED.
        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'REQUESTED',
            'received_at' => null,
        ]);
    }

    public function test_return_cannot_be_received_twice_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        $url = route('returns.receive', [
            'returnRequestId' => $returnId,
        ]);

        $this->patchJson($url)->assertOk();

        $return = ReturnRequest::findOrFail($returnId);

        $firstReceivedAt = $return->received_at;

        // La seconde réception est interdite.
        $this->patchJson($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(
            'RECEIVED',
            $return->fresh()->status
        );

        $this->assertEquals(
            $firstReceivedAt,
            $return->fresh()->received_at
        );
    }

    public function test_user_without_permission_cannot_receive_return_over_http(): void
    {
        [$returnId, $order, $owner] =
            $this->preparePendingReturnForReviewHttp();

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertForbidden();

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'APPROVED',
            'received_at' => null,
        ]);
    }

    public function test_other_store_cannot_receive_return_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        $otherStore = Store::create([
            'name' => 'Boutique externe réception',
            'slug' => 'boutique-externe-reception',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);

        app(TenantContext::class)->setFromUser($otherOwner);

        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertNotFound();

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'APPROVED',
            'received_at' => null,
        ]);
    }


    private function prepareReceivedReturnForInspectionHttp(): array
    {
        [$returnId, $order, $owner] =
            $this->preparePendingReturnForReviewHttp();

        // Approuver le retour.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        // Confirmer la réception.
        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertOk();

        $returnItem = ReturnRequest::findOrFail($returnId)
            ->items()
            ->firstOrFail();

        return [$returnId, $returnItem, $owner];
    }


    public function test_owner_can_inspect_received_return_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        // Approuver le retour.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        // Réceptionner le retour.
        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertOk();

        $item = ReturnRequest::findOrFail($returnId)
            ->items()
            ->firstOrFail();

        // Inspecter l'article.
        $this->patchJson(
            route('returns.inspect', [
                'returnRequestId' => $returnId,
            ]),
            [
                'items' => [
                    [
                        'return_request_item_id' => $item->id,
                        'restockable_quantity' => 1,
                        'inspection_notes' => 'Article en bon état',
                    ],
                ],
            ]
        )
            ->assertOk()
            ->assertJsonPath('return.status', 'INSPECTED')
            ->assertJsonPath(
                'return.items.0.restockable_quantity',
                1
            );

        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'INSPECTED',
        ]);
    }


    public function test_return_cannot_be_inspected_before_receipt_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        // Le commerçant approuve le retour.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        $item = ReturnRequest::findOrFail($returnId)
            ->items()
            ->firstOrFail();

        // Tentative d'inspection sans réception.
        $this->patchJson(
            route('returns.inspect', [
                'returnRequestId' => $returnId,
            ]),
            [
                'items' => [
                    [
                        'return_request_item_id' => $item->id,
                        'restockable_quantity' => 0,
                    ],
                ],
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // Le retour doit rester APPROVED.
        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'APPROVED',
            'received_at' => null,
            'inspected_at' => null,
        ]);
    }


    public function test_inspection_rejects_excessive_restockable_quantity_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        // Approuver le retour.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        // Confirmer la réception.
        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertOk();

        $item = ReturnRequest::findOrFail($returnId)
            ->items()
            ->firstOrFail();

        // Un article a été retourné.
        $this->assertSame(1, (int) $item->quantity);

        // Tentative de déclarer deux articles revendables.
        $this->patchJson(
            route('returns.inspect', [
                'returnRequestId' => $returnId,
            ]),
            [
                'items' => [
                    [
                        'return_request_item_id' => $item->id,
                        'restockable_quantity' => 2,
                    ],
                ],
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restockable_quantity');

        // Le retour reste RECEIVED.
        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'RECEIVED',
            'inspected_at' => null,
        ]);

        // Aucune quantité revendable enregistrée.
        $this->assertNull(
            $item->fresh()->restockable_quantity
        );

        // Aucun mouvement de stock RETURN.
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );
    }


    public function test_return_cannot_be_inspected_twice_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        // Approuver le retour.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        // Confirmer la réception.
        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertOk();

        $item = ReturnRequest::findOrFail($returnId)
            ->items()
            ->firstOrFail();

        $url = route('returns.inspect', [
            'returnRequestId' => $returnId,
        ]);

        $payload = [
            'items' => [
                [
                    'return_request_item_id' => $item->id,
                    'restockable_quantity' => 1,
                    'inspection_notes' => 'Article revendable',
                ],
            ],
        ];

        // Première inspection autorisée.
        $this->patchJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('return.status', 'INSPECTED');

        $firstInspectedAt = ReturnRequest::findOrFail($returnId)
            ->inspected_at;

        // Deuxième inspection interdite.
        $this->patchJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $return = ReturnRequest::findOrFail($returnId);

        $this->assertSame('INSPECTED', $return->status);

        $this->assertEquals(
            $firstInspectedAt,
            $return->inspected_at
        );

        // Le résultat initial reste inchangé.
        $this->assertSame(
            1,
            (int) $item->fresh()->restockable_quantity
        );

        // Aucun mouvement de stock RETURN.
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );
    }


    public function test_user_without_permission_cannot_inspect_return_over_http(): void
    {
        [$returnId, $order, $owner] =
            $this->preparePendingReturnForReviewHttp();

        // Le commerçant approuve le retour.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        // Confirmer la réception.
        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertOk();

        $item = ReturnRequest::findOrFail($returnId)
            ->items()
            ->firstOrFail();

        // Créer un utilisateur sans permission.
        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // Tentative d'inspection non autorisée.
        $this->patchJson(
            route('returns.inspect', [
                'returnRequestId' => $returnId,
            ]),
            [
                'items' => [
                    [
                        'return_request_item_id' => $item->id,
                        'restockable_quantity' => 1,
                    ],
                ],
            ]
        )->assertForbidden();

        // Aucune inspection enregistrée.
        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'RECEIVED',
            'inspected_at' => null,
        ]);

        // Aucun article marqué comme revendable.
        $this->assertNull(
            $item->fresh()->restockable_quantity
        );
    }


    public function test_other_store_cannot_inspect_return_over_http(): void
    {
        [$returnId] = $this->preparePendingReturnForReviewHttp();

        // Le commerçant de la boutique A approuve le retour.
        $this->patchJson(
            route('returns.review', [
                'returnRequestId' => $returnId,
            ]),
            ['decision' => 'APPROVED']
        )->assertOk();

        // Confirmer la réception.
        $this->patchJson(
            route('returns.receive', [
                'returnRequestId' => $returnId,
            ])
        )->assertOk();

        $item = ReturnRequest::findOrFail($returnId)
            ->items()
            ->firstOrFail();

        // Créer une deuxième boutique.
        $otherStore = Store::create([
            'name' => 'Boutique externe inspection',
            'slug' => 'boutique-externe-inspection',
            'status' => 'ACTIVE',
        ]);

        // Créer son commerçant.
        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        // Se connecter comme commerçant de la boutique B.
        $this->actingAs($otherOwner);

        app(TenantContext::class)->setFromUser($otherOwner);

        // Tenter d'inspecter le retour de la boutique A.
        $this->patchJson(
            route('returns.inspect', [
                'returnRequestId' => $returnId,
            ]),
            [
                'items' => [
                    [
                        'return_request_item_id' => $item->id,
                        'restockable_quantity' => 1,
                    ],
                ],
            ]
        )->assertNotFound();

        // Le retour original reste inchangé.
        $this->assertDatabaseHas('return_requests', [
            'id' => $returnId,
            'status' => 'RECEIVED',
            'inspected_at' => null,
        ]);

        $this->assertDatabaseHas('return_request_items', [
            'id' => $item->id,
            'restockable_quantity' => null,
        ]);
    }


    public function test_owner_can_restock_inspected_return_over_http(): void
    {
        // Préparer un retour déjà réceptionné
        // et inspecté : 2 articles revendables.
        [$return, $stock, $owner] =
            $this->prepareInspectedReturnForRestock();

        $returnItem = $return->items()->firstOrFail();

        // Stock avant remise en stock.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Demande HTTP de remise en stock.
        $response = $this->patchJson(
            route('returns.restock', [
                'returnRequestId' => $return->id,
            ])
        );

        $response->assertOk()
            ->assertJsonPath('return.status', 'INSPECTED');

        // Deux articles revendables sont réintégrés.
        $this->assertSame(
            8,
            (int) $stock->fresh()->physical_quantity
        );

        // L'article retourné est marqué comme traité.
        $this->assertNotNull(
            $returnItem->fresh()->restocked_at
        );

        // Un seul mouvement RETURN doit être créé.
        $movements = StockMovement::query()
            ->where('type', 'RETURN')
            ->get();

        $this->assertCount(1, $movements);

        $movement = $movements->first();

        $this->assertSame(
            2,
            (int) $movement->quantity
        );

        $this->assertSame(
            (int) $stock->product_variant_id,
            (int) $movement->product_variant_id
        );

        $this->assertSame(
            (int) $owner->id,
            (int) $movement->created_by
        );

        // La remise en stock ne change pas
        // le statut du retour.
        $this->assertSame(
            'INSPECTED',
            $return->fresh()->status
        );
    }


    public function test_return_cannot_be_restocked_twice_over_http(): void
    {
        [$return, $stock] =
            $this->prepareInspectedReturnForRestock();

        $url = route('returns.restock', [
            'returnRequestId' => $return->id,
        ]);

        // Première remise en stock autorisée.
        $this->patchJson($url)->assertOk();

        $this->assertSame(
            8,
            (int) $stock->fresh()->physical_quantity
        );

        $returnItem = $return->items()->firstOrFail();

        $firstRestockedAt = $returnItem->fresh()->restocked_at;

        $this->assertNotNull($firstRestockedAt);

        // Une seule entrée RETURN a été enregistrée.
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );

        // Deuxième tentative interdite.
        $this->patchJson($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');

        // Le stock ne doit pas augmenter à nouveau.
        $this->assertSame(
            8,
            (int) $stock->fresh()->physical_quantity
        );

        // Aucun mouvement supplémentaire.
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );

        // La date du premier traitement reste inchangée.
        $this->assertEquals(
            $firstRestockedAt,
            $returnItem->fresh()->restocked_at
        );

        $this->assertSame(
            'INSPECTED',
            $return->fresh()->status
        );
    }


    public function test_user_without_permission_cannot_restock_return_over_http(): void
    {
        // Préparer un retour inspecté.
        [$return, $stock, $owner] =
            $this->prepareInspectedReturnForRestock();

        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Créer un utilisateur de la même boutique,
        // sans les permissions nécessaires.
        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // Tentative de remise en stock non autorisée.
        $this->patchJson(
            route('returns.restock', [
                'returnRequestId' => $return->id,
            ])
        )->assertForbidden();

        // Le stock physique reste inchangé.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Aucun mouvement RETURN créé.
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );

        // Aucun article marqué comme remis en stock.
        $returnItem = $return->items()->firstOrFail();

        $this->assertNull(
            $returnItem->fresh()->restocked_at
        );

        // Le retour reste INSPECTED.
        $this->assertSame(
            'INSPECTED',
            $return->fresh()->status
        );
    }


    public function test_other_store_cannot_restock_return_over_http(): void
    {
        // Préparer un retour inspecté dans la boutique A.
        [$return, $stock, $owner] =
            $this->prepareInspectedReturnForRestock();

        $returnItem = $return->items()->firstOrFail();

        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Créer une deuxième boutique.
        $otherStore = Store::create([
            'name' => 'Boutique externe restock',
            'slug' => 'boutique-externe-restock',
            'status' => 'ACTIVE',
        ]);

        // Créer le commerçant de la boutique B.
        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        // Se connecter à la boutique B.
        $this->actingAs($otherOwner);

        app(TenantContext::class)->setFromUser($otherOwner);

        // Tenter de remettre en stock un retour
        // appartenant à la boutique A.
        $this->patchJson(
            route('returns.restock', [
                'returnRequestId' => $return->id,
            ])
        )->assertNotFound();

        // Le stock physique de la boutique A
        // ne doit pas être modifié.
        $this->assertDatabaseHas('stock_levels', [
            'id' => $stock->id,
            'physical_quantity' => 6,
        ]);

        // Aucun article marqué comme remis en stock.
        $this->assertDatabaseHas('return_request_items', [
            'id' => $returnItem->id,
            'restocked_at' => null,
        ]);

        // Le retour doit rester INSPECTED.
        $this->assertDatabaseHas('return_requests', [
            'id' => $return->id,
            'status' => 'INSPECTED',
        ]);

        // Aucun mouvement RETURN créé
        // dans la boutique A.
        $this->assertDatabaseMissing('stock_movements', [
            'store_id' => $owner->store_id,
            'type' => 'RETURN',
        ]);
    }


    public function test_zero_restockable_quantity_over_http(): void
    {
        // Retour inspecté : aucun article revendable.
        [$return, $stock] =
            $this->prepareInspectedReturnForRestock(0);

        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Confirmer le traitement via HTTP.
        $this->patchJson(
            route('returns.restock', [
                'returnRequestId' => $return->id,
            ])
        )
            ->assertOk()
            ->assertJsonPath('return.status', 'INSPECTED');

        // Aucun ajout au stock physique.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Aucun mouvement RETURN.
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('type', 'RETURN')
                ->count()
        );

        // Le traitement est malgré tout enregistré.
        $returnItem = $return->items()->firstOrFail();

        $this->assertNotNull(
            $returnItem->fresh()->restocked_at
        );

        $this->assertSame(
            0,
            (int) $returnItem->fresh()->restockable_quantity
        );

        // Une nouvelle tentative est interdite.
        $this->patchJson(
            route('returns.restock', [
                'returnRequestId' => $return->id,
            ])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');
    }


    public function test_owner_can_prepare_refund_over_http(): void
    {
        // Préparer un retour inspecté :
        // 3 articles retournés à 5000 FCFA.
        [$return, $order, $stock] =
            $this->prepareRefundScenario();

        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Préparer le remboursement via HTTP.
        $response = $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        );

        $response->assertCreated()
            ->assertJsonPath('refund.status', 'PENDING')
            ->assertJsonPath('refund.amount', '15000.00')
            ->assertJsonPath('refund.method', 'CASH')
            ->assertJsonPath(
                'refund.return_request_id',
                $return->id
            );

        // Un seul remboursement préparé.
        $this->assertDatabaseCount('refunds', 1);

        $this->assertDatabaseHas('refunds', [
            'return_request_id' => $return->id,
            'order_id' => $order->id,
            'amount' => '15000.00',
            'status' => 'PENDING',
            'simulated_at' => null,
        ]);

        // Le paiement original reste confirmé.
        $payment = $order->payments()->firstOrFail();

        $this->assertSame(
            'CONFIRMED',
            $payment->fresh()->status
        );

        // Aucun changement du stock.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );
    }


    public function test_refund_cannot_be_prepared_twice_over_http(): void
    {
        [$return, $order, $stock] =
            $this->prepareRefundScenario();

        $url = route('refunds.prepare', [
            'returnRequestId' => $return->id,
        ]);

        // Première préparation autorisée.
        $response = $this->postJson($url);

        $response->assertCreated()
            ->assertJsonPath('refund.status', 'PENDING')
            ->assertJsonPath('refund.amount', '15000.00');

        $refundId = $response->json('refund.id');

        $this->assertDatabaseCount('refunds', 1);

        // Deuxième préparation interdite.
        $this->postJson($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('refund');

        // Un seul remboursement existe toujours.
        $this->assertDatabaseCount('refunds', 1);

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'order_id' => $order->id,
            'return_request_id' => $return->id,
            'amount' => '15000.00',
            'status' => 'PENDING',
            'simulated_at' => null,
        ]);

        // Aucun changement du stock.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Le paiement initial reste confirmé.
        $payment = $order->payments()->firstOrFail();

        $this->assertSame(
            'CONFIRMED',
            $payment->fresh()->status
        );
    }


    public function test_user_without_permission_cannot_prepare_refund_over_http(): void
    {
        // Préparer un retour inspecté.
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        // Créer un utilisateur de la même boutique,
        // sans permission de remboursement.
        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // Tentative de préparation non autorisée.
        $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        )->assertForbidden();

        // Aucun remboursement créé.
        $this->assertDatabaseCount('refunds', 0);

        // Le stock reste inchangé.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Le paiement original reste confirmé.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'CONFIRMED',
            'amount' => '21000.00',
        ]);
    }


    public function test_other_store_cannot_prepare_refund_over_http(): void
    {
        // Préparer un retour inspecté dans la boutique A.
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        // Créer une deuxième boutique.
        $otherStore = Store::create([
            'name' => 'Boutique externe remboursement',
            'slug' => 'boutique-externe-remboursement',
            'status' => 'ACTIVE',
        ]);

        // Créer le commerçant de la boutique B.
        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        // Se connecter comme commerçant de la boutique B.
        $this->actingAs($otherOwner);

        app(TenantContext::class)->setFromUser($otherOwner);

        // Tenter de préparer un remboursement
        // appartenant à la boutique A.
        $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        )->assertNotFound();

        // Aucun remboursement ne doit être créé.
        $this->assertDatabaseCount('refunds', 0);

        // Le retour original reste inchangé.
        $this->assertDatabaseHas('return_requests', [
            'id' => $return->id,
            'store_id' => $owner->store_id,
            'status' => 'INSPECTED',
        ]);

        // Le paiement de la boutique A reste confirmé.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'store_id' => $owner->store_id,
            'status' => 'CONFIRMED',
            'amount' => '21000.00',
        ]);

        // Aucun changement du stock physique.
        $this->assertDatabaseHas('stock_levels', [
            'id' => $stock->id,
            'physical_quantity' => 6,
        ]);
    }


    public function test_owner_can_approve_refund_over_http(): void
    {
        // Préparer un retour inspecté.
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        // Préparer le remboursement via HTTP.
        $response = $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        );

        $response->assertCreated()
            ->assertJsonPath('refund.status', 'PENDING');

        $refundId = $response->json('refund.id');

        // Approuver le remboursement via HTTP.
        $response = $this->patchJson(
            route('refunds.approve', [
                'refundId' => $refundId,
            ])
        );

        $response->assertOk()
            ->assertJsonPath('refund.status', 'APPROVED')
            ->assertJsonPath('refund.amount', '15000.00')
            ->assertJsonPath('refund.approved_by', $owner->id);

        // La date d'approbation est enregistrée.
        $this->assertNotNull(
            $response->json('refund.approved_at')
        );

        // Aucun remboursement réel ou simulé
        // n'a encore été exécuté.
        $this->assertNull(
            $response->json('refund.simulated_at')
        );

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'order_id' => $order->id,
            'status' => 'APPROVED',
            'amount' => '15000.00',
        ]);

        // Le paiement initial reste confirmé.
        $payment = $order->payments()->firstOrFail();

        $this->assertSame(
            'CONFIRMED',
            $payment->fresh()->status
        );

        // Aucun changement du stock.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );
    }


    public function test_refund_cannot_be_approved_twice_over_http(): void
    {
        [$return, $order, $stock] =
            $this->prepareRefundScenario();

        // Préparer le remboursement.
        $response = $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        );

        $response->assertCreated();

        $refundId = $response->json('refund.id');

        $url = route('refunds.approve', [
            'refundId' => $refundId,
        ]);

        // Première approbation autorisée.
        $this->patchJson($url)
            ->assertOk()
            ->assertJsonPath('refund.status', 'APPROVED');

        $refund = Refund::findOrFail($refundId);

        $firstApprovedAt = $refund->approved_at;

        $this->assertNotNull($firstApprovedAt);

        // Deuxième approbation interdite.
        $this->patchJson($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // Les informations restent inchangées.
        $this->assertSame(
            'APPROVED',
            $refund->fresh()->status
        );

        $this->assertEquals(
            $firstApprovedAt,
            $refund->fresh()->approved_at
        );

        $this->assertDatabaseCount('refunds', 1);

        // Aucun remboursement réel ou simulé.
        $this->assertNull(
            $refund->fresh()->simulated_at
        );

        // Stock inchangé.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );
    }


    public function test_user_without_permission_cannot_approve_refund_over_http(): void
    {
        // Préparer un retour inspecté.
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        // Le commerçant prépare le remboursement.
        $response = $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        );

        $response->assertCreated();

        $refundId = $response->json('refund.id');

        // Créer un utilisateur de la même boutique
        // sans la permission refunds.manage.
        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        // Tentative d'approbation non autorisée.
        $this->patchJson(
            route('refunds.approve', [
                'refundId' => $refundId,
            ])
        )->assertForbidden();

        // Le remboursement reste en attente.
        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'order_id' => $order->id,
            'status' => 'PENDING',
            'approved_by' => null,
            'approved_at' => null,
            'simulated_at' => null,
        ]);

        // Aucun changement du stock.
        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        // Le paiement original reste confirmé.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'CONFIRMED',
            'amount' => '21000.00',
        ]);
    }


    public function test_other_store_cannot_approve_refund_over_http(): void
    {
        // Préparer un retour inspecté dans la boutique A.
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        // Préparer le remboursement.
        $response = $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        );

        $response->assertCreated();

        $refundId = $response->json('refund.id');

        // Créer une deuxième boutique.
        $otherStore = Store::create([
            'name' => 'Boutique externe approbation',
            'slug' => 'boutique-externe-approbation',
            'status' => 'ACTIVE',
        ]);

        // Créer son commerçant.
        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        // Se connecter comme commerçant de la boutique B.
        $this->actingAs($otherOwner);

        app(TenantContext::class)->setFromUser($otherOwner);

        // Tentative d'approbation du remboursement
        // appartenant à la boutique A.
        $this->patchJson(
            route('refunds.approve', [
                'refundId' => $refundId,
            ])
        )->assertNotFound();

        // Le remboursement reste en attente.
        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'store_id' => $owner->store_id,
            'order_id' => $order->id,
            'status' => 'PENDING',
            'approved_by' => null,
            'approved_at' => null,
            'simulated_at' => null,
        ]);

        // Le paiement original reste confirmé.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'store_id' => $owner->store_id,
            'status' => 'CONFIRMED',
        ]);

        // Le stock de la boutique A reste inchangé.
        $this->assertDatabaseHas('stock_levels', [
            'id' => $stock->id,
            'physical_quantity' => 6,
        ]);
    }


    private function prepareApprovedRefundForHttpTests(): array
    {
        [$return, $order, $stock, $owner] =
            $this->prepareRefundScenario();

        $response = $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        )->assertCreated();

        $refundId = (int) $response->json('refund.id');

        $this->patchJson(
            route('refunds.approve', [
                'refundId' => $refundId,
            ])
        )->assertOk();

        return [$refundId, $order, $stock, $owner];
    }

    // 1. Consultation des remboursements.
    public function test_refund_http_owner_can_list_and_view(): void
    {
        [$refundId] = $this->prepareApprovedRefundForHttpTests();

        $this->getJson(route('refunds.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $refundId]);

        $this->getJson(route('refunds.show', [
            'refundId' => $refundId,
        ]))
            ->assertOk()
            ->assertJsonPath('refund.status', 'APPROVED')
            ->assertJsonPath('refund.amount', '15000.00');
    }

    // 2. Simulation autorisée après approbation.
    public function test_refund_http_owner_can_simulate_approved_refund(): void
    {
        [$refundId, $order, $stock] =
            $this->prepareApprovedRefundForHttpTests();

        $response = $this->patchJson(
            route('refunds.simulate', [
                'refundId' => $refundId,
            ])
        );

        $response->assertOk()
            ->assertJsonPath('refund.status', 'SIMULATED')
            ->assertJsonPath('refund.amount', '15000.00');

        $this->assertNotNull(
            $response->json('refund.simulated_at')
        );

        $this->assertDatabaseCount('refunds', 1);

        $this->assertSame(
            6,
            (int) $stock->fresh()->physical_quantity
        );

        $this->assertSame(
            'CONFIRMED',
            $order->payments()->firstOrFail()->fresh()->status
        );
    }

    // 3. Interdire la simulation sans approbation.
    public function test_refund_http_pending_refund_cannot_be_simulated(): void
    {
        [$return] = $this->prepareRefundScenario();

        $response = $this->postJson(
            route('refunds.prepare', [
                'returnRequestId' => $return->id,
            ])
        )->assertCreated();

        $refundId = (int) $response->json('refund.id');

        $this->patchJson(route('refunds.simulate', [
            'refundId' => $refundId,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'status' => 'PENDING',
            'simulated_at' => null,
        ]);
    }

    // 4. Empêcher une deuxième simulation.
    public function test_refund_http_cannot_simulate_twice(): void
    {
        [$refundId] = $this->prepareApprovedRefundForHttpTests();

        $url = route('refunds.simulate', [
            'refundId' => $refundId,
        ]);

        $this->patchJson($url)->assertOk();

        $firstDate = Refund::findOrFail($refundId)->simulated_at;

        $this->patchJson($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $refund = Refund::findOrFail($refundId);

        $this->assertSame('SIMULATED', $refund->status);
        $this->assertEquals($firstDate, $refund->simulated_at);
        $this->assertDatabaseCount('refunds', 1);
    }

    // 5. Refuser un montant modifié après approbation.
    public function test_refund_http_rejects_modified_amount(): void
    {
        [$refundId] = $this->prepareApprovedRefundForHttpTests();

        $refund = Refund::findOrFail($refundId);
        $refund->amount = '14000.00';
        $refund->save();

        $this->patchJson(route('refunds.simulate', [
            'refundId' => $refundId,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'status' => 'APPROVED',
            'simulated_at' => null,
        ]);
    }

    // 6. Refuser une simulation sans paiement confirmé.
    public function test_refund_http_rejects_lost_payment_confirmation(): void
    {
        [$refundId, $order] =
            $this->prepareApprovedRefundForHttpTests();

        $order->payments()->firstOrFail()->update([
            'status' => 'PENDING',
        ]);

        $this->patchJson(route('refunds.simulate', [
            'refundId' => $refundId,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'status' => 'APPROVED',
            'simulated_at' => null,
        ]);
    }

    // 7. Refuser un utilisateur sans permission.
    public function test_refund_http_denies_user_without_permission(): void
    {
        [$refundId, $order, $stock, $owner] =
            $this->prepareApprovedRefundForHttpTests();

        $user = User::factory()->create([
            'store_id' => $owner->store_id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->getJson(route('refunds.index'))
            ->assertForbidden();

        $this->getJson(route('refunds.show', [
            'refundId' => $refundId,
        ]))->assertForbidden();

        $this->patchJson(route('refunds.simulate', [
            'refundId' => $refundId,
        ]))->assertForbidden();

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'status' => 'APPROVED',
            'simulated_at' => null,
        ]);
    }

    // 8. Empêcher l'accès depuis une autre boutique.
    public function test_refund_http_enforces_store_isolation(): void
    {
        [$refundId, $order, $stock, $owner] =
            $this->prepareApprovedRefundForHttpTests();

        $otherStore = Store::create([
            'name' => 'Boutique externe simulation',
            'slug' => 'boutique-externe-simulation',
            'status' => 'ACTIVE',
        ]);

        $otherOwner = User::factory()->create([
            'store_id' => $otherStore->id,
        ]);

        $otherOwner->roles()->attach(
            Role::where('code', 'STORE_OWNER')
                ->firstOrFail()->id
        );

        $this->actingAs($otherOwner);
        app(TenantContext::class)->setFromUser($otherOwner);

        $this->getJson(route('refunds.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(route('refunds.show', [
            'refundId' => $refundId,
        ]))->assertNotFound();

        $this->patchJson(route('refunds.simulate', [
            'refundId' => $refundId,
        ]))->assertNotFound();

        $this->assertDatabaseHas('refunds', [
            'id' => $refundId,
            'store_id' => $owner->store_id,
            'status' => 'APPROVED',
            'simulated_at' => null,
        ]);
    }

    // 9. Interdire l'accès aux visiteurs non connectés.
    public function test_refund_http_denies_guests(): void
    {
        $this->getJson(route('refunds.index'))
            ->assertUnauthorized();

        $this->getJson(route('refunds.show', [
            'refundId' => 1,
        ]))->assertUnauthorized();

        $this->patchJson(route('refunds.simulate', [
            'refundId' => 1,
        ]))->assertUnauthorized();
    }
}
