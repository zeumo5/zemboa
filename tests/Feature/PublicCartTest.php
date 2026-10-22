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

        // Only the owner store can clear this session cart.
        $this->deleteJson('/shops/beta/cart')->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->getJson('/shops/alpha/cart')->assertJsonPath('data.items.'.$variantA->id, 1);
        $this->deleteJson('/shops/alpha/cart')->assertOk();

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

    public function test_html_product_and_cart_use_the_existing_json_contract(): void
    {
        $variant = $this->createShop('alpha');
        $this->get('/boutiques/alpha/produits/'.$variant->product_id)
            ->assertOk()->assertSee('data-cart-form', false)
            ->assertSee('/shops/alpha/cart/items', false)
            ->assertSee('name="product_variant_id"', false)
            ->assertSee('name="_token"', false);
        $this->get('/boutiques/alpha/panier')->assertOk()
            ->assertSee('data-cart-page', false)->assertSee('/shops/alpha/cart', false);
        $this->postJson('/shops/alpha/cart/items', [
            'product_variant_id' => $variant->id, 'quantity' => 2, 'price' => 1,
        ])->assertOk()->assertJsonPath('data.details.0.final_unit_price', '5000.00');
        $this->get('/')->assertOk()->assertSee('/boutiques/alpha/panier', false);
        $this->patchJson('/shops/alpha/cart/items/'.$variant->id, ['quantity' => 9])
            ->assertUnprocessable();
        $this->getJson('/shops/alpha/cart')->assertJsonPath('data.details.0.quantity', 2);
        $this->deleteJson('/shops/alpha/cart/items/'.$variant->id)->assertOk()
            ->assertJsonPath('data.items', []);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_html_cart_points_to_original_store_without_replacing_its_items(): void
    {
        $a = $this->createShop('alpha');
        $b = $this->createShop('beta');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 1])->assertOk();
        $this->get('/boutiques/beta/panier')->assertOk()
            ->assertSee('/boutiques/alpha/panier', false);
        $this->postJson('/shops/beta/cart/items', ['product_variant_id' => $b->id, 'quantity' => 1])->assertUnprocessable();
        $this->getJson('/shops/alpha/cart')->assertJsonPath('data.details.0.product_variant_id', $a->id);
        $a->store->update(['status' => 'SUSPENDED']);
        $this->get('/boutiques/beta/panier')->assertOk()->assertSee(__('cart.store_unavailable', [], 'fr'));
        $b->store->update(['status' => 'SUSPENDED']);
        $this->get('/boutiques/beta/panier')->assertNotFound();
        $this->getJson('/shops/beta/cart')->assertNotFound();
        $this->postJson('/shops/beta/cart/items', ['product_variant_id' => $b->id, 'quantity' => 1])->assertNotFound();
        $this->patchJson('/shops/beta/cart/items/'.$b->id, ['quantity' => 1])->assertNotFound();
        $this->deleteJson('/shops/beta/cart/items/'.$b->id)->assertNotFound();
        $this->postJson('/shops/beta/checkout', [])->assertNotFound();
        $this->assertSame(1, session('zemboa.cart.items.'.$a->id));
    }

    public function test_html_cart_is_bilingual_and_json_mutations_require_csrf(): void
    {
        $this->createShop('alpha');
        $this->withSession(['locale' => 'en'])->get('/boutiques/alpha/panier')
            ->assertOk()->assertSee('Continue exploring');
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            new class($this->app, $this->app->make('encrypter')) extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {
                protected function runningUnitTests() { return false; }
            });
        $this->postJson('/shops/alpha/cart/items', [])->assertStatus(419);
        $this->patchJson('/shops/alpha/cart/items/1', ['quantity' => 1])->assertStatus(419);
        $this->deleteJson('/shops/alpha/cart/items/1')->assertStatus(419);
        $this->deleteJson('/shops/alpha/cart')->assertStatus(419);
        $this->postJson('/shops/alpha/cart/switch', [])->assertStatus(419);
    }

    public function test_foreign_clear_cannot_discard_a_suspended_store_cart(): void
    {
        $a = $this->createShop('alpha');
        $this->createShop('beta');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 2])->assertOk();
        $a->store->update(['status' => 'SUSPENDED']);
        $this->deleteJson('/shops/alpha/cart')->assertNotFound();
        $this->deleteJson('/shops/beta/cart')->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertSame(2, session('zemboa.cart.items.'.$a->id));
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_cards_choose_direct_options_or_unavailable_and_queries_stay_constant(): void
    {
        $a = $this->createShop('alpha');
        $this->get('/produits')->assertOk()->assertSee('data-cart-direct="'.$a->product_id.'"', false);
        $this->get('/boutiques/alpha/produits')->assertOk()->assertSee('data-cart-direct="'.$a->product_id.'"', false);
        app(TenantContext::class)->setFromStore($a->store);
        $second = ProductVariant::create(['product_id' => $a->product_id, 'sku' => 'SECOND', 'price' => '7000.00', 'status' => 'ACTIVE']);
        $this->get('/produits')->assertOk()->assertSee('data-cart-options="'.$a->product_id.'"', false)
            ->assertDontSee('data-cart-direct="'.$a->product_id.'"', false);
        $a->stockLevel->update(['physical_quantity' => 2]);
        $this->get('/produits')->assertOk()->assertSee('data-cart-unavailable="'.$a->product_id.'"', false);
        $this->get('/boutiques/alpha/produits')->assertOk()->assertSee('data-cart-unavailable="'.$a->product_id.'"', false);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->get('/produits')->assertOk();
        $one = count(\Illuminate\Support\Facades\DB::getQueryLog());
        for ($i = 0; $i < 5; $i++) { $this->createShop('extra-'.$i); }
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->get('/produits')->assertOk();
        $this->assertSame($one, count(\Illuminate\Support\Facades\DB::getQueryLog()));
        \Illuminate\Support\Facades\DB::disableQueryLog();
    }

    public function test_badge_counts_products_and_identifies_the_original_store(): void
    {
        $a = $this->createShop('alpha');
        $this->createShop('beta');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 3])
            ->assertOk()->assertJsonPath('data.store.id', $a->store_id)
            ->assertJsonPath('data.cart_url', route('marketplace.shops.cart', ['shopSlug' => 'alpha']));
        $html = $this->get('/boutiques/beta/produits')->assertOk()->getContent();
        preg_match('/data-cart-count[^>]*>([0-9]+)<\/span>/', $html, $matches);
        $this->assertSame('1', $matches[1]);
        preg_match('/<span[^>]*data-cart-origin([^>]*)>/', $html, $origin);
        $this->assertStringNotContainsString('hidden', $origin[1]);
        $this->getJson('/shops/beta/cart')->assertJsonPath('data.items', []);
        $this->assertSame(3, session('zemboa.cart.items.'.$a->id));
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_buyer_login_preserves_badge_products_and_store(): void
    {
        $a = $this->createShop('alpha');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 2])->assertOk();
        $buyer = \App\Models\User::factory()->create([
            'store_id' => null, 'is_buyer' => true, 'password' => 'CartBuyerTest123!',
        ]);
        $this->post('/client/connexion', ['email' => $buyer->email, 'password' => 'CartBuyerTest123!'])
            ->assertRedirect(route('buyer.account'));
        $html = $this->get('/client/compte')->assertOk()->getContent();
        preg_match('/data-cart-count[^>]*>([0-9]+)<\/span>/', $html, $matches);
        $this->assertSame('1', $matches[1]);
        $this->assertStringContainsString('/boutiques/alpha/panier', $html);
        $this->assertSame(2, session('zemboa.cart.items.'.$a->id));
        $this->getJson('/shops/alpha/cart')->assertOk()->assertJsonPath('data.counts.distinct_products', 1)->assertJsonPath('data.counts.units', 2);
        $this->assertGuest('web');
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_clear_owned_cart_keeps_business_data_and_exposes_confirmation(): void
    {
        $a = $this->createShop('alpha');
        $stock = $a->stockLevel->getRawOriginal();
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 2])->assertOk();
        $this->get('/boutiques/alpha/panier')->assertOk()
            ->assertSee('data-cart-clear-dialog', false)->assertSee('data-cart-clear-cancel', false)
            ->assertSee('data-cart-clear-confirm', false)->assertSee('data-clear-url="'.url('/shops/alpha/cart').'"', false);
        $this->deleteJson('/shops/alpha/cart')->assertOk()
            ->assertJsonPath('data.items', [])->assertJsonPath('data.totals.total', '0.00');
        $this->assertNull(session('zemboa.cart'));
        $this->assertSame($stock, $a->stockLevel()->firstOrFail()->getRawOriginal());
        foreach (['stock_reservations', 'orders', 'payments'] as $table) { $this->assertDatabaseCount($table, 0); }
    }

    public function test_public_variant_labels_use_real_attribute_names_and_values(): void
    {
        $a = $this->createShop('alpha');
        $attribute = \App\Models\Attribute::create(['name' => 'Taille', 'slug' => 'taille', 'status' => 'ACTIVE']);
        $value = \App\Models\AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'M']);
        app(\App\Actions\ProductVariant\AttachAttributeValue::class)->execute($a->id, $value->id);
        $this->get('/boutiques/alpha/produits/'.$a->product_id)->assertOk()->assertSee('Taille: M');
        $this->get('/boutiques/alpha/produits')->assertOk()->assertSee('Taille: M');
    }

    public function test_distinct_products_group_variants_and_keep_unavailable_lines(): void
    {
        $a = $this->createShop('alpha');
        $second = ProductVariant::create(['product_id' => $a->product_id, 'sku' => 'SECOND', 'price' => '6000.00', 'status' => 'ACTIVE']);
        StockLevel::create(['product_variant_id' => $second->id, 'physical_quantity' => 10, 'reserved_quantity' => 0, 'low_stock_threshold' => 1]);
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 5])->assertOk();
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $second->id, 'quantity' => 3])->assertOk()
            ->assertJsonPath('data.counts.distinct_products', 1)->assertJsonPath('data.counts.units', 8)
            ->assertJsonPath('data.details.0.product_id', $a->product_id)->assertJsonPath('data.products_subtotal', '43000.00');
        $this->patchJson('/shops/alpha/cart/items/'.$a->id, ['quantity' => 1])->assertOk()->assertJsonPath('data.counts.units', 4);
        $second->update(['status' => 'INACTIVE']);
        $this->getJson('/shops/alpha/cart')->assertOk()->assertJsonPath('data.counts.distinct_products', 1)
            ->assertJsonPath('data.counts.units', 4)->assertJsonPath('data.details.1.available', false);
        $this->get('/produits')->assertOk()->assertSee('>1</span>', false);
        $this->assertSame(3, session('zemboa.cart.items.'.$second->id));
        $this->deleteJson('/shops/alpha/cart/items/'.$second->id)->assertOk()->assertJsonPath('data.counts.units', 1);
        $this->deleteJson('/shops/alpha/cart')->assertOk()->assertJsonPath('data.counts.distinct_products', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_orphaned_lines_are_preserved_without_inventing_product_identity(): void
    {
        $a = $this->createShop('alpha');
        $this->withSession(['zemboa.cart' => ['store_id' => $a->store_id, 'items' => [999999 => 2]]]);
        $this->getJson('/shops/alpha/cart')->assertOk()->assertJsonPath('data.counts.distinct_products', 0)
            ->assertJsonPath('data.counts.unidentified_lines', 1)->assertJsonPath('data.counts.units', 2)
            ->assertJsonPath('data.details.0.product_id', null);
        $this->get('/produits')->assertOk()->assertSee('>0+</span>', false);
        $this->assertSame(2, session('zemboa.cart.items.999999'));
    }

    public function test_quantity_and_summary_controls_use_existing_routes(): void
    {
        $a = $this->createShop('alpha');
        $this->get('/boutiques/alpha/produits/'.$a->product_id)->assertOk()->assertSee('data-cart-current', false)
            ->assertSee('data-quantity-stepper', false)->assertSee('data-cart-back', false);
        $this->get('/boutiques/alpha/panier')->assertOk()->assertSee('data-cart-summary', false)
            ->assertSee('data-cart-products', false)->assertSee('data-cart-units', false);
    }

    public function test_three_distinct_products_and_ten_units_remain_distinct(): void
    {
        $a = $this->createShop('alpha');
        foreach ([3, 2] as $i => $quantity) {
            $product = Product::create(['category_id' => $a->product->category_id, 'name' => 'Extra '.$i, 'slug' => 'extra-'.$i, 'status' => 'ACTIVE']);
            $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'EXTRA-'.$i, 'price' => '1000.00', 'status' => 'ACTIVE']);
            StockLevel::create(['product_variant_id' => $variant->id, 'physical_quantity' => 10, 'reserved_quantity' => 0, 'low_stock_threshold' => 1]);
            $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $variant->id, 'quantity' => $quantity])->assertOk();
        }
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 5])->assertOk()
            ->assertJsonPath('data.counts.distinct_products', 3)->assertJsonPath('data.counts.units', 10);
        $this->get('/')->assertOk()->assertSee('>3</span>', false);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_confirmed_switch_abandons_only_current_session_and_never_adds_implicitly(): void
    {
        $a = $this->createShop('alpha');
        $stock = $a->stockLevel->getRawOriginal();
        $b = $this->createShop('beta');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 2])->assertOk();
        $before = session('zemboa.cart');
        $foreign = $this->getJson('/shops/beta/cart')->assertOk()->json('data.foreign_cart');
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->assertSame($before, session('zemboa.cart')); // Inspection/cancellation never clears.
        $this->assertSame(route('marketplace.shops.cart', ['shopSlug' => 'alpha']), $foreign['cart_url']);
        $this->postJson('/shops/beta/cart/items', ['product_variant_id' => $b->id, 'quantity' => 1])->assertUnprocessable();
        $this->deleteJson('/shops/beta/cart')->assertUnprocessable();
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $foreign['confirmation_token']])
            ->assertOk()->assertJsonPath('data.cart_switched', true)->assertJsonPath('data.items', []);
        $this->assertNull(session('zemboa.cart'));
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $foreign['confirmation_token']])->assertUnprocessable();
        $this->postJson('/shops/beta/cart/items', ['product_variant_id' => $b->id, 'quantity' => 1])->assertOk();
        $this->assertSame($b->store_id, session('zemboa.cart.store_id'));
        app(TenantContext::class)->setFromStore(Store::findOrFail($a->store_id));
        $this->assertSame($stock, $a->stockLevel()->firstOrFail()->getRawOriginal());
        foreach (['orders', 'payments', 'stock_reservations'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_switch_rejects_changed_cart_forged_token_and_wrong_destination(): void
    {
        $a = $this->createShop('alpha'); $this->createShop('beta'); $this->createShop('gamma');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 1])->assertOk();
        $token = $this->getJson('/shops/beta/cart')->json('data.foreign_cart.confirmation_token');
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->postJson('/shops/gamma/cart/switch', ['confirmation_token' => $token])->assertUnprocessable();
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => str_repeat('0', 64)])->assertUnprocessable();
        $this->patchJson('/shops/alpha/cart/items/'.$a->id, ['quantity' => 2])->assertOk();
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $token])->assertUnprocessable();
        $this->patchJson('/shops/alpha/cart/items/'.$a->id, ['quantity' => 1])->assertOk();
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $token])->assertUnprocessable();
        $this->assertSame(1, session('zemboa.cart.items.'.$a->id));
        $this->assertSame('file', config('session.block_store'));
        $route = app('router')->getRoutes()->getByName('public.shops.cart.switch');
        $this->assertNull($route->locksFor());
        $this->assertTrue(config('session.block'));
        $this->assertSame(120, config('session.block_lock_seconds'));
        $this->assertSame(10, config('session.block_wait_seconds'));
    }

    public function test_switch_does_not_bypass_suspended_owner_or_destination(): void
    {
        $a = $this->createShop('alpha'); $b = $this->createShop('beta');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 1])->assertOk();
        $token = $this->getJson('/shops/beta/cart')->json('data.foreign_cart.confirmation_token');
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        Store::whereKey($a->store_id)->update(['status' => 'SUSPENDED']);
        $this->getJson('/shops/beta/cart')->assertOk()->assertJsonPath('data.foreign_cart.confirmation_token', null);
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $token])->assertUnprocessable();
        Store::whereKey($b->store_id)->update(['status' => 'SUSPENDED']);
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $token])->assertNotFound();
        $this->assertSame(1, session('zemboa.cart.items.'.$a->id));
    }

    public function test_recreated_cart_and_different_session_cannot_replay_confirmation(): void
    {
        $a = $this->createShop('alpha'); $this->createShop('beta');
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 1])->assertOk();
        $token = $this->getJson('/shops/beta/cart')->json('data.foreign_cart.confirmation_token');
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->deleteJson('/shops/alpha/cart')->assertOk();
        $this->postJson('/shops/alpha/cart/items', ['product_variant_id' => $a->id, 'quantity' => 1])->assertOk();
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $token])->assertUnprocessable();
        $fresh = $this->getJson('/shops/beta/cart')->json('data.foreign_cart.confirmation_token');
        $this->assertNotSame($token, $fresh);
        session()->migrate();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->postJson('/shops/beta/cart/switch', ['confirmation_token' => $fresh])->assertUnprocessable();
        $this->postJson('/shops/beta/cart/switch', [])->assertUnprocessable();
        $this->assertSame(1, session('zemboa.cart.items.'.$a->id));
    }

    public function test_cart_rejects_non_persistent_lock_configuration(): void
    {
        $this->createShop('alpha');
        config(['cache.stores.file.driver' => 'array']);
        $this->getJson('/shops/alpha/cart')->assertStatus(503);
    }
}
