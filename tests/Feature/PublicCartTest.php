<?php

namespace Tests\Feature;


use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCartTest extends TestCase
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

    private function createShop(string $slug): ProductVariant
    {
        $store = Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);

        app(TenantContext::class)->setFromStore($store);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones-' . $slug,
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
            'sku' => 'CART-' . strtoupper($slug),
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        StockLevel::create([
            'product_variant_id' => $variant->id,
            'physical_quantity' => 10,
            'reserved_quantity' => 2,
            'low_stock_threshold' => 5,
        ]);

        return $variant;
    }

    public function test_guest_can_manage_cart_without_changing_stock(): void
    {
        $variant = $this->createShop('alpha');

        $url = '/shops/alpha/cart/items';

        $this->postJson($url, [
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.items.' . $variant->id,
                2
            );

        $this->patchJson($url . '/' . $variant->id, [
            'quantity' => 3,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.items.' . $variant->id,
                3
            );

        $this->getJson('/shops/alpha/cart')
            ->assertOk()
            ->assertJsonPath(
                'data.items.' . $variant->id,
                3
            );

        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stock->physical_quantity);
        $this->assertSame(2, $stock->reserved_quantity);

        $this->deleteJson($url . '/' . $variant->id)
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_cart_cannot_mix_two_stores(): void
    {
        $variantA = $this->createShop('alpha');
        $variantB = $this->createShop('beta');

        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variantA->id,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/shops/beta/cart/items', [
            'product_variant_id' => $variantB->id,
            'quantity' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cart']);

        $this->getJson('/shops/alpha/cart')
            ->assertJsonPath(
                'data.items.' . $variantA->id,
                1
            );

        // Vider le panier depuis la boutique B.
        $this->deleteJson('/shops/beta/cart')
            ->assertOk();

        $this->postJson('/shops/beta/cart/items', [
            'product_variant_id' => $variantB->id,
            'quantity' => 1,
        ])->assertOk();
    }

    public function test_unavailable_products_are_rejected(): void
    {
        $variant = $this->createShop('alpha');

        $variant->update(['status' => 'INACTIVE']);

        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertNotFound();

        $variant->update(['status' => 'ACTIVE']);

        // Stock disponible = 10 - 2 = 8 unités.
        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 9,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_cart_recalculates_prices_and_promotions(): void
    {
        $variant = $this->createShop('alpha');

        // Prix normal : 5 000 FCFA.
        // Prix promotionnel : 4 000 FCFA.
        $variant->update([
            'promo_price' => '4000.00',
        ]);

        // Ajouter 2 articles au panier.
        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.totals.subtotal', '10000.00')
            ->assertJsonPath('data.totals.discount_amount', '2000.00')
            ->assertJsonPath('data.totals.total', '8000.00')
            ->assertJsonPath('data.can_checkout', true)
            ->assertJsonPath('data.details.0.line_total', '8000.00');

        // Le commerçant modifie ses prix.
        $variant->update([
            'price' => '6000.00',
            'promo_price' => '4500.00',
        ]);

        // Le panier doit utiliser les nouveaux prix.
        $this->getJson('/shops/alpha/cart')
            ->assertOk()
            ->assertJsonPath('data.totals.subtotal', '12000.00')
            ->assertJsonPath('data.totals.discount_amount', '3000.00')
            ->assertJsonPath('data.totals.total', '9000.00')
            ->assertJsonPath('data.details.0.final_unit_price', '4500.00')
            ->assertJsonPath('data.can_checkout', true);

        // Vérifier que le panier n'a modifié aucun stock.
        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stock->physical_quantity);
        $this->assertSame(2, $stock->reserved_quantity);

        $this->assertDatabaseCount('stock_reservations', 0);
    }


    public function test_guest_checkout_creates_order_and_reserves_stock(): void
    {
        $variant = $this->createShop('alpha');

        // Ajouter deux articles au panier.
        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertOk();

        // Valider la commande sans compte utilisateur.
        $response = $this->postJson('/shops/alpha/checkout', [
            'customer_name' => 'Jean Client',
            'customer_phone' => '690000001',
            'fulfillment_type' => 'PICKUP',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'UNPAID')
            ->assertJsonPath('data.total', '10000.00');

        $orderId = $response->json('data.order_id');

        // La commande et sa réservation existent.
        $this->assertDatabaseCount('orders', 1);

        $this->assertDatabaseHas('stock_reservations', [
            'product_variant_id' => $variant->id,
            'reference_type' => 'ORDER',
            'reference_id' => $orderId,
            'quantity' => 2,
            'status' => 'ACTIVE',
        ]);

        // Stock physique inchangé : seules les réservations augmentent.
        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(10, $stock->physical_quantity);
        $this->assertSame(4, $stock->reserved_quantity);

        // Le panier est vidé après la création réussie.
        $this->getJson('/shops/alpha/cart')
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_failed_checkout_keeps_cart_and_creates_no_order(): void
    {
        $variant = $this->createShop('alpha');

        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 3,
        ])->assertOk();

        // Simuler une diminution du stock disponible.
        $stock = $variant->stockLevel()->firstOrFail();

        $stock->update([
            'reserved_quantity' => 9,
        ]);

        // Seulement une unité reste disponible.
        $this->postJson('/shops/alpha/checkout', [
            'customer_name' => 'Jean Client',
            'customer_phone' => '690000001',
            'fulfillment_type' => 'PICKUP',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cart']);

        // Aucune commande ni réservation supplémentaire.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);

        // Le client conserve son panier.
        $this->getJson('/shops/alpha/cart')
            ->assertOk()
            ->assertJsonPath(
                'data.items.' . $variant->id,
                3
            )
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_empty_checkout_message_is_in_french(): void
    {
        $this->createShop('alpha');

        // Le visiteur utilise le français.
        $this->withSession([
            'locale' => 'fr',
        ]);

        // Essayer de commander avec un panier vide.
        $this->postJson('/shops/alpha/checkout', [
            'customer_name' => 'Jean Client',
            'customer_phone' => '690000001',
            'fulfillment_type' => 'PICKUP',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.cart.0',
                'Votre panier est vide.'
            );

        // Aucune commande ne doit être créée.
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_empty_checkout_message_is_in_english(): void
    {
        $this->createShop('alpha');

        // Le visiteur utilise l'anglais.
        $this->withSession([
            'locale' => 'en',
        ]);

        $this->postJson('/shops/alpha/checkout', [
            'customer_name' => 'John Customer',
            'customer_phone' => '690000001',
            'fulfillment_type' => 'PICKUP',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.cart.0',
                'Your cart is empty.'
            );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_english_checkout_creates_order_and_reserves_stock(): void
    {
        $variant = $this->createShop('alpha');

        // Le visiteur choisit English.
        $this->withSession([
            'locale' => 'en',
        ]);

        // Ajouter deux produits au panier.
        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertOk();

        // Passer commande.
        $response = $this->postJson(
            '/shops/alpha/checkout',
            [
                'customer_name' => 'John Customer',
                'customer_phone' => '690000001',
                'fulfillment_type' => 'PICKUP',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Order created successfully.'
            )
            ->assertJsonPath(
                'data.payment_status',
                'UNPAID'
            )
            ->assertJsonPath(
                'data.total',
                '10000.00'
            );

        $orderId = $response->json('data.order_id');

        // Une seule commande créée.
        $this->assertDatabaseCount('orders', 1);

        // La réservation reste fonctionnelle.
        $this->assertDatabaseHas('stock_reservations', [
            'product_variant_id' => $variant->id,
            'reference_type' => 'ORDER',
            'reference_id' => $orderId,
            'quantity' => 2,
            'status' => 'ACTIVE',
        ]);

        // Aucun stock physique décrémenté
        // avant confirmation du paiement.
        $stock = $variant->stockLevel()->firstOrFail();

        $this->assertSame(
            10,
            $stock->physical_quantity
        );

        $this->assertSame(
            4,
            $stock->reserved_quantity
        );

        // Le panier est vidé après succès.
        $this->getJson('/shops/alpha/cart')
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_delivery_zone_required_message_is_in_english(): void
    {
        $variant = $this->createShop('alpha');

        $this->withSession([
            'locale' => 'en',
        ]);

        // Ajouter un produit disponible.
        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertOk();

        // Demander une livraison sans zone.
        $this->postJson('/shops/alpha/checkout', [
            'customer_name' => 'John Customer',
            'customer_phone' => '690000001',
            'fulfillment_type' => 'DELIVERY',
            'delivery_address' => 'Douala, Bonaberi',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.delivery_zone_id.0',
                'A delivery zone is required for delivery orders.'
            );

        // La commande doit être refusée.
        $this->assertDatabaseCount('orders', 0);

        // Le panier doit être conservé.
        $this->getJson('/shops/alpha/cart')
            ->assertOk()
            ->assertJsonPath(
                'data.items.' . $variant->id,
                1
            );
    }

    public function test_unavailable_items_message_is_in_english(): void
    {
        $variant = $this->createShop('alpha');

        $this->withSession([
            'locale' => 'en',
        ]);

        // Ajouter trois articles au panier.
        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id,
            'quantity' => 3,
        ])->assertOk();

        // Simuler une diminution du stock disponible.
        $stock = $variant->stockLevel()->firstOrFail();

        $stock->update([
            'reserved_quantity' => 9,
        ]);

        // Il ne reste qu'une unité disponible.
        $this->postJson('/shops/alpha/checkout', [
            'customer_name' => 'John Customer',
            'customer_phone' => '690000001',
            'fulfillment_type' => 'PICKUP',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.cart.0',
                'Some items are no longer available. Please check your cart.'
            );

        // Aucune commande ni réservation supplémentaire.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);

        // Le panier conserve ses trois articles.
        $this->getJson('/shops/alpha/cart')
            ->assertOk()
            ->assertJsonPath(
                'data.items.' . $variant->id,
                3
            );
    }
}
