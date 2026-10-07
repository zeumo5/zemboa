<?php

namespace Tests\Feature;

use App\Actions\ProductVariant\CreateProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_owner_can_view_stock_level_from_own_store(): void
    {
        $this->seed();

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

        $user->roles()->attach($role);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Téléphones',
            'slug' => 'telephones',
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Samsung Galaxy S26',
            'slug' => 'samsung-galaxy-s26',
            'status' => 'ACTIVE',
            'is_featured' => false,
        ]);

        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'SGS26-HTTP-128',
            'price' => '450000.00',
            'status' => 'ACTIVE',
        ]);

        $stockLevel = $variant->stockLevel()
            ->firstOrFail();

        $response = $this
            ->actingAs($user)
            ->get(route('stock.show', $stockLevel));

        $response
            ->assertOk()
            ->assertJson([
                'id' => $stockLevel->id,
                'store_id' => $store->id,
                'product_variant_id' => $variant->id,
                'physical_quantity' => 0,
                'reserved_quantity' => 0,
            ]);
    }

    public function test_store_owner_cannot_view_stock_level_from_another_store(): void
{
    $this->seed();

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

    $role = Role::where('code', 'STORE_OWNER')
        ->firstOrFail();

    $userA->roles()->attach($role);
    $userB->roles()->attach($role);

    // Création du stock dans la boutique B.
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $productB = Product::create([
        'category_id' => $categoryB->id,
        'name' => 'iPhone Test',
        'slug' => 'iphone-test',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variantB = app(CreateProductVariant::class)->execute([
        'product_id' => $productB->id,
        'sku' => 'B-IPHONE-HTTP',
        'price' => '500000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevelB = $variantB->stockLevel()
        ->firstOrFail();

    // Retour dans le contexte de la boutique A.
    app(TenantContext::class)->setFromUser($userA);

    $response = $this
        ->actingAs($userA)
        ->get('/stock/' . $stockLevelB->id);

    $response->assertNotFound();
}
public function test_user_without_inventory_view_cannot_view_stock_level(): void
{
    $this->seed();

    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    // Utilisateur servant à créer les données de la boutique.
    $owner = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($owner);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy S26',
        'slug' => 'samsung-galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'SGS26-NO-VIEW',
        'price' => '450000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevel = $variant->stockLevel()
        ->firstOrFail();

    // Même boutique, mais aucun rôle/permission.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->get(route('stock.show', $stockLevel));

    $response->assertForbidden();
}

public function test_store_owner_can_receive_stock(): void
{
    $this->seed();

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

    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy S26',
        'slug' => 'samsung-galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'SGS26-RECEIVE',
        'price' => '450000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevel = $variant->stockLevel()
        ->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->post(
            route('stock.receive', $stockLevel),
            [
                'quantity' => 10,
                'reason' => 'Réception fournisseur',
            ]
        );

    $response->assertRedirect(
        route('stock.show', $stockLevel)
    );

    $stockLevel->refresh();

    $this->assertSame(
        10,
        $stockLevel->physical_quantity
    );

    $this->assertSame(
        0,
        $stockLevel->reserved_quantity
    );

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $store->id,
        'product_variant_id' => $variant->id,
        'type' => 'RECEIPT',
        'quantity' => 10,
        'reason' => 'Réception fournisseur',
        'created_by' => $user->id,
    ]);
}

public function test_user_without_inventory_manage_cannot_receive_stock(): void
{
    $this->seed();

    $store = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    $owner = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($owner);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy S26',
        'slug' => 'samsung-galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'SGS26-NO-MANAGE',
        'price' => '450000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevel = $variant->stockLevel()
        ->firstOrFail();

    // Même boutique, mais aucune permission inventory.manage.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->post(
            route('stock.receive', $stockLevel),
            [
                'quantity' => 10,
                'reason' => 'Tentative interdite',
            ]
        );

    $response->assertForbidden();

    $stockLevel->refresh();

    $this->assertSame(0, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);

    $this->assertDatabaseMissing('stock_movements', [
        'product_variant_id' => $variant->id,
        'type' => 'RECEIPT',
        'quantity' => 10,
    ]);
}

public function test_receive_stock_rejects_zero_quantity(): void
{
    $this->seed();

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

    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy S26',
        'slug' => 'samsung-galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'SGS26-ZERO',
        'price' => '450000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevel = $variant->stockLevel()
        ->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->post(
            route('stock.receive', $stockLevel),
            [
                'quantity' => 0,
                'reason' => 'Quantité invalide',
            ]
        );

    $response->assertSessionHasErrors('quantity');

    $stockLevel->refresh();

    $this->assertSame(0, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);

    $this->assertDatabaseMissing('stock_movements', [
        'product_variant_id' => $variant->id,
        'type' => 'RECEIPT',
    ]);
}

public function test_store_owner_can_adjust_stock_in(): void
{
    $this->seed();

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

    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy S26',
        'slug' => 'samsung-galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'SGS26-ADJUST-IN',
        'price' => '450000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevel = $variant->stockLevel()
        ->firstOrFail();

    $response = $this
        ->actingAs($user)
        ->post(
            route('stock.adjust-in', $stockLevel),
            [
                'quantity' => 3,
                'reason' => 'Écart positif après inventaire',
            ]
        );

    $response->assertRedirect(
        route('stock.show', $stockLevel)
    );

    $stockLevel->refresh();

    $this->assertSame(3, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $store->id,
        'product_variant_id' => $variant->id,
        'type' => 'ADJUSTMENT_IN',
        'quantity' => 3,
        'reason' => 'Écart positif après inventaire',
        'created_by' => $user->id,
    ]);
}

public function test_store_owner_can_adjust_stock_out(): void
{
    $this->seed();

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

    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy S26',
        'slug' => 'samsung-galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'SGS26-ADJUST-OUT',
        'price' => '450000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevel = $variant->stockLevel()
        ->firstOrFail();

    // On place d'abord 10 unités en stock.
    app(\App\Actions\Stock\ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $user->id,
        reason: 'Stock initial'
    );

    $response = $this
        ->actingAs($user)
        ->post(
            route('stock.adjust-out', $stockLevel),
            [
                'quantity' => 3,
                'reason' => 'Écart négatif après inventaire',
            ]
        );

    $response->assertRedirect(
        route('stock.show', $stockLevel)
    );

    $stockLevel->refresh();

    $this->assertSame(7, $stockLevel->physical_quantity);
    $this->assertSame(0, $stockLevel->reserved_quantity);

    $this->assertDatabaseHas('stock_movements', [
        'store_id' => $store->id,
        'product_variant_id' => $variant->id,
        'type' => 'ADJUSTMENT_OUT',
        'quantity' => 3,
        'reason' => 'Écart négatif après inventaire',
        'created_by' => $user->id,
    ]);
}

public function test_adjust_stock_out_cannot_reduce_physical_stock_below_reserved_stock(): void
{
    $this->seed();

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

    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Samsung Galaxy S26',
        'slug' => 'samsung-galaxy-s26',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = app(CreateProductVariant::class)->execute([
        'product_id' => $product->id,
        'sku' => 'SGS26-RESERVED',
        'price' => '450000.00',
        'status' => 'ACTIVE',
    ]);

    $stockLevel = $variant->stockLevel()
        ->firstOrFail();

    app(\App\Actions\Stock\ReceiveStock::class)->execute(
        productVariantId: $variant->id,
        quantity: 10,
        userId: $user->id,
        reason: 'Stock initial'
    );

    // 8 unités sur les 10 sont déjà réservées.
    $stockLevel->reserved_quantity = 8;
    $stockLevel->save();

    $movementsBefore = \App\Models\StockMovement::query()
        ->count();

    // Retirer 3 ferait passer le stock physique à 7,
    // alors que 8 unités sont réservées : opération interdite.
    $response = $this
        ->actingAs($user)
        ->post(
            route('stock.adjust-out', $stockLevel),
            [
                'quantity' => 3,
                'reason' => 'Correction impossible',
            ]
        );

    $response->assertSessionHasErrors('quantity');

    $stockLevel->refresh();

    $this->assertSame(10, $stockLevel->physical_quantity);
    $this->assertSame(8, $stockLevel->reserved_quantity);
    $this->assertSame(2, $stockLevel->availableQuantity());

    $this->assertSame(
        $movementsBefore,
        \App\Models\StockMovement::query()->count()
    );
}
}