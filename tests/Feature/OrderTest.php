<?php

namespace Tests\Feature;

use App\Actions\Orders\CancelOrder;
use App\Models\StockReservation;
use App\Models\DeliveryZone;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\ReceiveStock;
use App\Actions\Orders\CreateOrder;
use App\Actions\Customers\FindOrCreateCustomer;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_is_automatically_attached_to_current_store(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $customer = Customer::create([
            'name' => 'Jean Client',
            'phone' => '+237690000001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-TEST-001',
            'fulfillment_type' => 'PICKUP',

            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',

            'subtotal' => '25000.00',
            'discount_amount' => '0.00',
            'delivery_fee' => '0.00',
            'total' => '25000.00',
        ]);

        $order->refresh();

        $this->assertSame($store->id, $order->store_id);
        $this->assertSame($customer->id, $order->customer_id);

        $this->assertSame('PENDING', $order->status);
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame('PICKUP', $order->fulfillment_type);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => 'ZM-TEST-001',
            'status' => 'PENDING',
            'payment_status' => 'UNPAID',
        ]);
    }

    public function test_order_queries_are_isolated_by_store(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Commande de Boutique A
        app(TenantContext::class)->setFromUser($userA);

        $customerA = Customer::create([
            'name' => 'Client Alpha',
            'phone' => '+237690000001',
        ]);

        $orderA = Order::create([
            'customer_id' => $customerA->id,
            'order_number' => 'ZM-TEST-A',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Alpha',
            'customer_phone' => '+237690000001',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        // Commande de Boutique B
        app(TenantContext::class)->setFromUser($userB);

        $customerB = Customer::create([
            'name' => 'Client Beta',
            'phone' => '+237690000002',
        ]);

        $orderB = Order::create([
            'customer_id' => $customerB->id,
            'order_number' => 'ZM-TEST-B',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Beta',
            'customer_phone' => '+237690000002',
            'subtotal' => '15000.00',
            'total' => '15000.00',
        ]);

        // Retour dans Boutique A
        app(TenantContext::class)->setFromUser($userA);

        $orders = Order::query()->get();

        $this->assertTrue(
            $orders->contains('id', $orderA->id)
        );

        $this->assertFalse(
            $orders->contains('id', $orderB->id)
        );

        $this->assertCount(1, $orders);
    }

    public function test_order_can_have_items_with_price_snapshots(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $customer = Customer::create([
            'name' => 'Jean Client',
            'phone' => '+237690000001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-TEST-ITEM-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Jean Client',
            'customer_phone' => '+237690000001',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'IPH17-128',
            'price' => 5000,
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        $item = $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => 'Produit Test',
            'variant_description' => 'Taille M',
            'sku' => 'TEST-001',
            'unit_price' => '5000.00',
            'discount_amount' => '0.00',
            'final_unit_price' => '5000.00',
            'quantity' => 2,
            'line_total' => '10000.00',
        ]);

        $this->assertSame($order->id, $item->order_id);
        $this->assertSame('5000.00', $item->unit_price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('10000.00', $item->line_total);

        $this->assertTrue(
            $order->items()->whereKey($item->id)->exists()
        );
    }

    public function test_find_or_create_customer_creates_customer_for_current_store(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $customer = app(FindOrCreateCustomer::class)->execute(
            name: 'Jean Client',
            phone: '690 000 001',
            email: 'jean@example.com',
        );

        $this->assertSame($store->id, $customer->store_id);
        $this->assertSame('Jean Client', $customer->name);
        $this->assertSame('+237690000001', $customer->phone);
        $this->assertSame('jean@example.com', $customer->email);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'store_id' => $store->id,
            'phone' => '+237690000001',
        ]);
    }

    public function test_find_or_create_customer_reuses_existing_customer_without_overwriting_profile(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $existingCustomer = Customer::create([
            'name' => 'Jean Original',
            'phone' => '+237690000001',
            'email' => 'original@example.com',
        ]);

        $customer = app(FindOrCreateCustomer::class)->execute(
            name: 'Jean Checkout',
            phone: '690 000 001',
            email: 'checkout@example.com',
        );

        $this->assertSame($existingCustomer->id, $customer->id);

        $this->assertSame('Jean Original', $customer->name);
        $this->assertSame('+237690000001', $customer->phone);
        $this->assertSame('original@example.com', $customer->email);

        $this->assertSame(
            1,
            Customer::query()
                ->where('phone', '+237690000001')
                ->count()
        );
    }

    public function test_find_or_create_customer_never_reuses_customer_from_another_store(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Client existant dans Boutique B.
        app(TenantContext::class)->setFromUser($userB);

        $customerB = Customer::create([
            'name' => 'Client Beta',
            'phone' => '+237690000001',
        ]);

        // Même téléphone utilisé dans Boutique A.
        app(TenantContext::class)->setFromUser($userA);

        $customerA = app(FindOrCreateCustomer::class)->execute(
            name: 'Client Alpha',
            phone: '690 000 001',
            email: 'alpha@example.com',
        );

        $this->assertNotSame($customerB->id, $customerA->id);

        $this->assertSame($storeA->id, $customerA->store_id);
        $this->assertSame('+237690000001', $customerA->phone);

        // Vérification du client B depuis son propre tenant.
        app(TenantContext::class)->setFromUser($userB);

        $this->assertDatabaseHas('customers', [
            'id' => $customerB->id,
            'store_id' => $storeB->id,
            'phone' => '+237690000001',
        ]);
    }

    public function test_create_order_creates_pickup_order_with_server_calculated_amounts(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-128',
            'price' => '10000.00',
            'promo_price' => '8000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial pour checkout'
        );

        $order = app(CreateOrder::class)->execute(
            customerName: 'Jean Client',
            customerPhone: '690 000 001',
            customerEmail: 'jean@example.com',
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        );

        $this->assertSame($store->id, $order->store_id);
        $this->assertSame('PENDING', $order->status);
        $this->assertSame('UNPAID', $order->payment_status);
        $this->assertSame('PICKUP', $order->fulfillment_type);

        $this->assertSame('Jean Client', $order->customer_name);
        $this->assertSame('+237690000001', $order->customer_phone);
        $this->assertSame('jean@example.com', $order->customer_email);

        $this->assertSame('20000.00', $order->subtotal);
        $this->assertSame('4000.00', $order->discount_amount);
        $this->assertSame('0.00', $order->delivery_fee);
        $this->assertSame('16000.00', $order->total);

        $this->assertCount(1, $order->items);

        $item = $order->items->first();

        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame('10000.00', $item->unit_price);
        $this->assertSame('2000.00', $item->discount_amount);
        $this->assertSame('8000.00', $item->final_unit_price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('16000.00', $item->line_total);

        $reservation = $variant->stockReservations()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($reservation);
        $this->assertNotNull($order->reservation_expires_at);

        $this->assertTrue(
            $order->reservation_expires_at->equalTo($reservation->expires_at)
        );
        $this->assertSame('ACTIVE', $reservation->status);
        $this->assertSame(2, $reservation->quantity);
        $this->assertNotNull($reservation->expires_at);

        $stockLevel = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stockLevel->physical_quantity);
        $this->assertSame(2, $stockLevel->reserved_quantity);
        $this->assertSame(8, $stockLevel->availableQuantity());
    }

    public function test_create_order_rolls_back_when_stock_is_insufficient(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-128',
            'price' => '10000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 1,
            userId: $user->id,
            reason: 'Stock initial'
        );

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: 'jean@example.com',
                fulfillmentType: 'PICKUP',
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 2,
                    ],
                ],
            );

            $this->fail('La commande aurait dû être refusée.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(0, Order::query()->count());

        $this->assertSame(
            0,
            $variant->stockReservations()->count()
        );

        $stockLevel = $variant->stockLevel()->firstOrFail();

        $this->assertSame(1, $stockLevel->physical_quantity);
        $this->assertSame(0, $stockLevel->reserved_quantity);
        $this->assertSame(1, $stockLevel->availableQuantity());
    }

    public function test_create_order_rolls_back_all_items_when_one_item_has_insufficient_stock(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        // Variante 1 : stock suffisant.
        $variantA = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-128',
            'price' => '10000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // Variante 2 : stock insuffisant.
        $variantB = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-256',
            'price' => '15000.00',
            'is_default' => false,
            'status' => 'ACTIVE',
        ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variantA->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial variante A'
        );

        app(ReceiveStock::class)->execute(
            productVariantId: $variantB->id,
            quantity: 1,
            userId: $user->id,
            reason: 'Stock initial variante B'
        );

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: 'jean@example.com',
                fulfillmentType: 'PICKUP',
                items: [
                    [
                        'product_variant_id' => $variantA->id,
                        'quantity' => 2,
                    ],
                    [
                        'product_variant_id' => $variantB->id,
                        'quantity' => 2,
                    ],
                ],
            );

            $this->fail(
                'La commande aurait dû être entièrement refusée.'
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'quantity',
                $exception->errors()
            );
        }

        // Aucune commande partielle.
        $this->assertSame(0, Order::query()->count());

        // Aucune réservation ne doit survivre.
        $this->assertSame(
            0,
            $variantA->stockReservations()->count()
        );

        $this->assertSame(
            0,
            $variantB->stockReservations()->count()
        );

        // Même la réservation réussie de A doit avoir été annulée
        // par le rollback de la transaction.
        $stockA = $variantA->stockLevel()->firstOrFail();
        $stockB = $variantB->stockLevel()->firstOrFail();

        $this->assertSame(10, $stockA->physical_quantity);
        $this->assertSame(0, $stockA->reserved_quantity);
        $this->assertSame(10, $stockA->availableQuantity());

        $this->assertSame(1, $stockB->physical_quantity);
        $this->assertSame(0, $stockB->reserved_quantity);
        $this->assertSame(1, $stockB->availableQuantity());
    }

    public function test_create_order_rejects_fractional_quantity(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-128',
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

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'PICKUP',
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => '2.5',
                    ],
                ],
            );

            $this->fail('Une quantité fractionnaire aurait dû être refusée.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $variant->stockReservations()->count());
    }

    public function test_create_order_rejects_empty_items(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'PICKUP',
                items: [],
            );

            $this->fail('Une commande sans article aurait dû être refusée.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_create_order_rejects_invalid_fulfillment_type(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'INVALID',
                items: [
                    [
                        'product_variant_id' => 1,
                        'quantity' => 1,
                    ],
                ],
            );

            $this->fail('Un type de livraison invalide aurait dû être refusé.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'fulfillment_type',
                $exception->errors()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_create_order_cannot_use_product_variant_from_another_store(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Création du produit dans la boutique B.
        app(TenantContext::class)->setFromUser($userB);

        $categoryB = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $productB = Product::create([
            'category_id' => $categoryB->id,
            'name' => 'Téléphone Boutique B',
            'slug' => 'telephone-boutique-b',
            'status' => 'ACTIVE',
        ]);

        $variantB = app(CreateProductVariant::class)->execute([
            'product_id' => $productB->id,
            'sku' => 'STORE-B-001',
            'price' => '10000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        app(ReceiveStock::class)->execute(
            productVariantId: $variantB->id,
            quantity: 10,
            userId: $userB->id,
            reason: 'Stock boutique B'
        );

        // On repasse maintenant dans la boutique A.
        app(TenantContext::class)->setFromUser($userA);

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'PICKUP',
                items: [
                    [
                        'product_variant_id' => $variantB->id,
                        'quantity' => 1,
                    ],
                ],
            );

            $this->fail(
                'Une boutique ne doit pas pouvoir commander une variante appartenant à une autre boutique.'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(
                ProductVariant::class,
                $exception->getModel()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_create_order_rejects_inactive_product_variant(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-INACTIVE',
            'price' => '10000.00',
            'is_default' => true,
            'status' => 'INACTIVE',
        ]);


        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $user->id,
            reason: 'Stock initial'
        );

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'PICKUP',
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 1,
                    ],
                ],
            );

            $this->fail('Une variante inactive aurait dû être refusée.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'items',
                $exception->errors()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $variant->stockReservations()->count());
    }

    public function test_create_order_rejects_variant_of_inactive_product(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        // Produit désactivé.
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'INACTIVE',
        ]);

        // Mais sa variante est encore ACTIVE.
        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-128',
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

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'PICKUP',
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 1,
                    ],
                ],
            );

            $this->fail(
                'Une variante appartenant à un produit inactif aurait dû être refusée.'
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'items',
                $exception->errors()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $variant->stockReservations()->count());
    }

    public function test_create_order_merges_duplicate_product_variants(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-128',
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
            customerPhone: '690 000 001',
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 3,
                ],
            ],
        );

        $this->assertCount(1, $order->items);

        $item = $order->items->first();

        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame(5, $item->quantity);
        $this->assertSame('50000.00', $item->line_total);

        $reservations = $variant->stockReservations()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->get();

        $this->assertCount(1, $reservations);
        $this->assertSame(5, $reservations->first()->quantity);

        $stockLevel = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stockLevel->physical_quantity);
        $this->assertSame(5, $stockLevel->reserved_quantity);
        $this->assertSame(5, $stockLevel->availableQuantity());
    }

    public function test_delivery_zone_is_automatically_attached_to_current_store(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $zone = DeliveryZone::create([
            'name' => 'Bonaberi',
            'city' => 'Douala',
            'fee' => '1500.00',
            'status' => 'ACTIVE',
        ]);

        $this->assertSame($store->id, $zone->store_id);
        $this->assertSame('1500.00', $zone->fee);

        $this->assertDatabaseHas('delivery_zones', [
            'id' => $zone->id,
            'store_id' => $store->id,
            'name' => 'Bonaberi',
            'city' => 'Douala',
        ]);
    }

    public function test_delivery_zone_queries_are_isolated_by_store(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Zone de la boutique B.
        app(TenantContext::class)->setFromUser($userB);

        $zoneB = DeliveryZone::create([
            'name' => 'Bonamoussadi',
            'city' => 'Douala',
            'fee' => '2000.00',
            'status' => 'ACTIVE',
        ]);

        // On repasse dans la boutique A.
        app(TenantContext::class)->setFromUser($userA);

        $this->assertNull(
            DeliveryZone::query()->find($zoneB->id)
        );

        $this->assertSame(0, DeliveryZone::query()->count());
    }

    public function test_delivery_order_requires_delivery_zone(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-DELIVERY',
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

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'DELIVERY',
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 1,
                    ],
                ],
            );

            $this->fail(
                'Une commande DELIVERY sans zone de livraison aurait dû être refusée.'
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'delivery_zone_id',
                $exception->errors()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $variant->stockReservations()->count());
    }

    public function test_delivery_order_uses_authoritative_delivery_zone_fee(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $zone = DeliveryZone::create([
            'name' => 'Bonaberi',
            'city' => 'Douala',
            'fee' => '1500.00',
            'status' => 'ACTIVE',
        ]);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
           'sku' => 'PICKUP-IGNORE-001',
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
            customerPhone: '690 000 001',
            customerEmail: null,
            fulfillmentType: 'DELIVERY',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                ],
            ],
            deliveryZoneId: $zone->id,
            deliveryAddress: 'Derrière le lycée polyvalent',
            deliveryArea: 'Mambanda',
            deliveryInstructions: 'Appeler à mon arrivée',
        );

        $this->assertSame('DELIVERY', $order->fulfillment_type);
        $this->assertSame('Bonaberi', $order->delivery_zone_name);

        $this->assertSame('Douala', $order->delivery_city);

        $this->assertSame(
            'Derrière le lycée polyvalent',
            $order->delivery_address
        );

        $this->assertSame(
            'Mambanda',
            $order->delivery_area
        );

        $this->assertSame(
            'Appeler à mon arrivée',
            $order->delivery_instructions
        );

        $this->assertSame('10000.00', $order->subtotal);
        $this->assertSame('1500.00', $order->delivery_fee);
        $this->assertSame('11500.00', $order->total);
    }

    public function test_delivery_order_cannot_use_zone_from_another_store(): void
    {
        $storeA = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique Beta',
            'slug' => 'boutique-beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Création de la zone dans la boutique B.
        app(TenantContext::class)->setFromUser($userB);

        $zoneB = DeliveryZone::create([
            'name' => 'Bonamoussadi',
            'city' => 'Douala',
            'fee' => '2000.00',
            'status' => 'ACTIVE',
        ]);

        // On passe maintenant dans la boutique A.
        app(TenantContext::class)->setFromUser($userA);

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'DELIVERY',
                items: [
                    [
                        // L'ID n'a pas besoin d'exister :
                        // la zone doit être refusée avant le checkout.
                        'product_variant_id' => 1,
                        'quantity' => 1,
                    ],
                ],
                deliveryZoneId: $zoneB->id,
                deliveryAddress: 'Adresse de test',
            );

            $this->fail(
                'Une boutique ne doit pas pouvoir utiliser la zone de livraison d’une autre boutique.'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(
                DeliveryZone::class,
                $exception->getModel()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_delivery_order_rejects_inactive_delivery_zone(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $zone = DeliveryZone::create([
            'name' => 'Bonaberi',
            'city' => 'Douala',
            'fee' => '1500.00',
            'status' => 'INACTIVE',
        ]);

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'DELIVERY',
                items: [
                    [
                        'product_variant_id' => 1,
                        'quantity' => 1,
                    ],
                ],
                deliveryZoneId: $zone->id,
                deliveryAddress: 'Adresse de test',
            );

            $this->fail(
                'Une zone de livraison inactive aurait dû être refusée.'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(
                DeliveryZone::class,
                $exception->getModel()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_delivery_order_requires_delivery_address(): void
    {
        $store = Store::create([
            'name' => 'Boutique Alpha',
            'slug' => 'boutique-alpha',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        app(TenantContext::class)->setFromUser($user);

        $zone = DeliveryZone::create([
            'name' => 'Bonaberi',
            'city' => 'Douala',
            'fee' => '1500.00',
            'status' => 'ACTIVE',
        ]);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'iPhone 17',
            'slug' => 'iphone-17',
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'IPH17-DELIVERY-ADDRESS',
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

        try {
            app(CreateOrder::class)->execute(
                customerName: 'Jean Client',
                customerPhone: '690 000 001',
                customerEmail: null,
                fulfillmentType: 'DELIVERY',
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 1,
                    ],
                ],
                deliveryZoneId: $zone->id,
            );


            $this->fail(
                'Une commande DELIVERY sans adresse aurait dû être refusée.'
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'delivery_address',
                $exception->errors()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $variant->stockReservations()->count());
    }

    public function test_pickup_order_ignores_delivery_information(): void
{
   $store = Store::create([
    'name' => 'Boutique Pickup',
    'slug' => 'boutique-pickup',
    'status' => 'ACTIVE',
]);

$user = User::factory()->create([
    'store_id' => $store->id,
]);

$this->actingAs($user);

app(TenantContext::class)->setFromUser($user);
    
$category = Category::create([
    'name' => 'Produits Pickup',
    'slug' => 'produits-pickup',
    'status' => 'ACTIVE',
]);

$product = Product::create([
    'category_id' => $category->id,
    'name' => 'Produit Pickup',
    'slug' => 'produit-pickup',
    'status' => 'ACTIVE',
]);

$variant = app(CreateProductVariant::class)->execute([
    'product_id' => $product->id,
    'sku' => 'PICKUP-IGNORE-001',
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
        customerName: 'Junior Zeumo',
        customerPhone: '690000001',
        customerEmail: null,
        fulfillmentType: 'PICKUP',
        items: [
            [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ],
        ],
        deliveryAddress: 'Une adresse qui doit être ignorée',
        deliveryArea: 'Bonaberi',
        deliveryInstructions: 'Appelez-moi',
    );

    $this->assertSame('PICKUP', $order->fulfillment_type);
    $this->assertNull($order->delivery_zone_name);
    $this->assertNull($order->delivery_city);
    $this->assertNull($order->delivery_area);
    $this->assertNull($order->delivery_address);
    $this->assertNull($order->delivery_instructions);
    $this->assertSame('0.00', $order->delivery_fee);
}

public function test_pending_unpaid_order_can_be_cancelled_and_releases_stock(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'iPhone 17',
        'slug' => 'iphone-17',
        'status' => 'ACTIVE',
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'IPH17-CANCEL',
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
        customerPhone: '690 000 001',
        customerEmail: null,
        fulfillmentType: 'PICKUP',
        items: [
            [
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ],
        ],
    );

    $reservation = StockReservation::query()
        ->where('reference_type', 'ORDER')
        ->where('reference_id', $order->id)
        ->firstOrFail();

    $this->assertSame('PENDING', $order->status);
    $this->assertSame('UNPAID', $order->payment_status);
    $this->assertSame('ACTIVE', $reservation->status);

    $stockLevel = $variant->stockLevel()->firstOrFail();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(2, $stockLevel->reserved_quantity);

    app(CancelOrder::class)->execute($order->id);

$order->refresh();
$reservation->refresh();
$stockLevel->refresh();

$this->assertSame('CANCELLED', $order->status);

$this->assertSame('RELEASED', $reservation->status);
$this->assertNotNull($reservation->released_at);

$this->assertSame(10, $stockLevel->physical_quantity);
$this->assertSame(0, $stockLevel->reserved_quantity);
$this->assertSame(10, $stockLevel->availableQuantity());
}

public function test_paid_order_cannot_be_cancelled(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-CANCEL-PAID',
        'status' => 'PENDING',
        'payment_status' => 'PAID',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'discount_amount' => '0.00',
        'delivery_fee' => '0.00',
        'total' => '10000.00',
    ]);

    try {
        app(CancelOrder::class)->execute($order->id);

        $this->fail(
            'Une commande payée ne devrait pas pouvoir être annulée directement.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'payment_status',
            $exception->errors()
        );
    }

    $order->refresh();

    $this->assertSame('PENDING', $order->status);
    $this->assertSame('PAID', $order->payment_status);
}

public function test_cancelled_order_cannot_be_cancelled_again(): void
{
    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ZM-CANCEL-TWICE',
        'status' => 'CANCELLED',
        'payment_status' => 'UNPAID',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Jean Client',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'discount_amount' => '0.00',
        'delivery_fee' => '0.00',
        'total' => '10000.00',
    ]);

    try {
        app(CancelOrder::class)->execute($order->id);

        $this->fail(
            'Une commande déjà annulée ne devrait pas pouvoir être annulée une deuxième fois.'
        );
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey(
            'status',
            $exception->errors()
        );
    }

    $order->refresh();

    $this->assertSame('CANCELLED', $order->status);
    $this->assertSame('UNPAID', $order->payment_status);
}

public function test_order_cannot_be_cancelled_from_another_store(): void
{
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    // Création de la commande dans la boutique B.
    app(TenantContext::class)->setFromUser($userB);

    $customerB = Customer::create([
        'name' => 'Client Beta',
        'phone' => '+237690000001',
    ]);

    $orderB = Order::create([
        'customer_id' => $customerB->id,
        'order_number' => 'ZM-CANCEL-STORE-B',
        'status' => 'PENDING',
        'payment_status' => 'UNPAID',
        'fulfillment_type' => 'PICKUP',
        'customer_name' => 'Client Beta',
        'customer_phone' => '+237690000001',
        'subtotal' => '10000.00',
        'discount_amount' => '0.00',
        'delivery_fee' => '0.00',
        'total' => '10000.00',
    ]);

    // On passe maintenant dans la boutique A.
    app(TenantContext::class)->setFromUser($userA);

    try {
        app(CancelOrder::class)->execute($orderB->id);

        $this->fail(
            'Une boutique ne devrait pas pouvoir annuler la commande d’une autre boutique.'
        );
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
        $this->assertSame(
            Order::class,
            $exception->getModel()
        );
    }

    // Vérification depuis le tenant propriétaire.
    app(TenantContext::class)->setFromUser($userB);

    $orderB->refresh();

    $this->assertSame('PENDING', $orderB->status);
    $this->assertSame('UNPAID', $orderB->payment_status);
}

}
