<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use App\Models\Category;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_owner_can_create_product_in_own_store(): void
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

        $storeOwnerRole = Role::where(
            'code',
            'STORE_OWNER'
        )->firstOrFail();

        $user->roles()->attach($storeOwnerRole);

        $this->actingAs($user);

        app(TenantContext::class)->setFromUser($user);

        $category = Category::create([
            'name' => 'Chaussures',
            'slug' => 'chaussures',
            'status' => 'ACTIVE',
        ]);

        $response = $this->post(
           route('products.store'),
            [
                'category_id' => $category->id,
                'name' => 'Nike Air Max',
                'slug' => 'nike-air-max',
                'description' => 'Chaussure de sport',
                'status' => 'ACTIVE',
                'is_featured' => false,

                'default_variant' => [
                    'price' => 45000,
                    'sku' => 'NIKE-AM-001',
                ],
                // tes donnees actuelles, sans les modifier
            ]
        );

        $product = \App\Models\Product::query()
            ->where('slug', 'nike-air-max')
            ->firstOrFail();

        $response->assertRedirect(
            route('products.show', $product)
        );

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'store_id' => $store->id,
            'category_id' => $category->id,
            'slug' => 'nike-air-max',
        ]);

        $this->assertDatabaseHas('product_variants', [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'sku' => 'NIKE-AM-001',
            'price' => 45000,
            'is_default' => true,
        ]);
    }

    public function test_user_without_products_create_permission_cannot_create_product(): void
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

    $this->actingAs($user);

    app(\App\Support\TenantContext::class)
        ->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $response = $this->post(
        route('products.store'),
        [
            'category_id' => $category->id,
            'name' => 'Nike Air Max',
            'slug' => 'nike-air-max',
            'status' => 'ACTIVE',

            'default_variant' => [
                'price' => 45000,
            ],
        ]
    );

    $response->assertForbidden();

    $this->assertDatabaseMissing('products', [
        'slug' => 'nike-air-max',
    ]);
}

public function test_store_owner_cannot_create_product_with_category_from_another_store(): void
{
    $this->seed();

    // Boutique A
    $storeA = Store::create([
        'name' => 'Boutique Alpha',
        'slug' => 'boutique-alpha',
        'status' => 'ACTIVE',
    ]);

    // Boutique B
    $storeB = Store::create([
        'name' => 'Boutique Beta',
        'slug' => 'boutique-beta',
        'status' => 'ACTIVE',
    ]);

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $storeOwnerRole = Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $userA->roles()->attach($storeOwnerRole);

    // On passe temporairement dans le contexte de B
   $userB = User::factory()->create([
    'store_id' => $storeB->id,
]);

// Contexte de la boutique B
app(TenantContext::class)->setFromUser($userB);

$categoryB = Category::create([
    'name' => 'Téléphones',
    'slug' => 'telephones',
    'status' => 'ACTIVE',
]);

    // On revient dans le contexte de A
    app(TenantContext::class)->setFromUser($userA);

    $response = $this
        ->actingAs($userA)
        ->post(
            route('products.store'),
            [
                'category_id' => $categoryB->id,
                'name' => 'iPhone Test',
                'slug' => 'iphone-test',
                'status' => 'ACTIVE',

                'default_variant' => [
                    'price' => 500000,
                ],
            ]
        );

    $response->assertSessionHasErrors('category_id');

    $this->assertDatabaseMissing('products', [
        'slug' => 'iphone-test',
    ]);
}

public function test_store_owner_cannot_create_two_products_with_same_slug_in_same_store(): void
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

    $storeOwnerRole = Role::where(
        'code',
        'STORE_OWNER'
    )->firstOrFail();

    $user->roles()->attach($storeOwnerRole);

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    // Premier produit
    $firstResponse = $this->post(
        route('products.store'),
        [
            'category_id' => $category->id,
            'name' => 'Nike Air Max',
            'slug' => 'nike-air-max',
            'status' => 'ACTIVE',
            'default_variant' => [
                'price' => 45000,
                'sku' => 'NIKE-001',
            ],
        ]
    );

    $firstResponse->assertRedirect();

    // Deuxième produit avec le même slug
    $secondResponse = $this->post(
        route('products.store'),
        [
            'category_id' => $category->id,
            'name' => 'Nike Air Max 2',
            'slug' => 'nike-air-max',
            'status' => 'ACTIVE',
            'default_variant' => [
                'price' => 50000,
                'sku' => 'NIKE-002',
            ],
        ]
    );

    $secondResponse->assertSessionHasErrors('slug');

    $this->assertDatabaseCount('products', 1);
}

public function test_different_stores_can_create_products_with_same_slug(): void
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

    $role = Role::where('code', 'STORE_OWNER')
        ->firstOrFail();

    // Boutique A
    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    $userA->roles()->attach($role);

    app(TenantContext::class)->setFromUser($userA);

    $categoryA = Category::create([
        'name' => 'Chaussures A',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $responseA = $this
        ->actingAs($userA)
        ->post(route('products.store'), [
            'category_id' => $categoryA->id,
            'name' => 'Nike Air Max A',
            'slug' => 'nike-air-max',
            'status' => 'ACTIVE',
            'default_variant' => [
                'price' => 45000,
                'sku' => 'NIKE-A-001',
            ],
        ]);

    $responseA->assertRedirect();

    // Boutique B
    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    $userB->roles()->attach($role);

    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Chaussures B',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $responseB = $this
        ->actingAs($userB)
        ->post(route('products.store'), [
            'category_id' => $categoryB->id,
            'name' => 'Nike Air Max B',
            'slug' => 'nike-air-max',
            'status' => 'ACTIVE',
            'default_variant' => [
                'price' => 50000,
                'sku' => 'NIKE-B-001',
            ],
        ]);

    $responseB->assertRedirect();

    $this->assertDatabaseCount('products', 2);

    $this->assertDatabaseHas('products', [
        'store_id' => $storeA->id,
        'slug' => 'nike-air-max',
    ]);

    $this->assertDatabaseHas('products', [
        'store_id' => $storeB->id,
        'slug' => 'nike-air-max',
    ]);
}

public function test_product_cannot_be_created_with_negative_default_price(): void
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

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $response = $this->post(
        route('products.store'),
        [
            'category_id' => $category->id,
            'name' => 'Produit invalide',
            'slug' => 'produit-invalide',
            'status' => 'ACTIVE',
            'default_variant' => [
                'price' => -5000,
            ],
        ]
    );

    $response->assertSessionHasErrors(
        'default_variant.price'
    );

    $this->assertDatabaseMissing('products', [
        'slug' => 'produit-invalide',
    ]);

    $this->assertDatabaseCount(
        'product_variants',
        0
    );
}

public function test_store_owner_can_view_product_from_own_store(): void
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

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $product = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this->get(
        route('products.show', $product)
    );

    $response
        ->assertOk()
        ->assertJson([
            'id' => $product->id,
            'name' => 'Nike Air Max',
            'slug' => 'nike-air-max',
        ]);
}

public function test_store_owner_cannot_view_product_from_another_store(): void
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

    // Création du produit dans la boutique B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $productB = \App\Models\Product::create([
        'category_id' => $categoryB->id,
        'name' => 'iPhone Test',
        'slug' => 'iphone-test',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // User A essaie d'accéder au produit de B
    app(TenantContext::class)->setFromUser($userA);

    $response = $this
        ->actingAs($userA)
        ->get('/products/' . $productB->id);

    $response->assertNotFound();
}

public function test_user_without_products_view_permission_cannot_view_product(): void
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
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $product = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // Cet utilisateur appartient bien à la boutique,
    // mais ne possède aucun rôle/permission.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->get(route('products.show', $product));

    $response->assertForbidden();
}

public function test_store_owner_can_update_product_in_own_store(): void
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

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $product = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Ancien nom',
        'slug' => 'ancien-nom',
        'description' => null,
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this->put(
        route('products.update', $product),
        [
            'category_id' => $category->id,
            'name' => 'Nouveau nom',
            'slug' => 'nouveau-nom',
            'description' => 'Nouvelle description',
            'status' => 'INACTIVE',
            'is_featured' => true,
        ]
    );

    $response->assertRedirect(
        route('products.show', $product)
    );

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Nouveau nom',
        'slug' => 'nouveau-nom',
        'description' => 'Nouvelle description',
        'status' => 'INACTIVE',
        'is_featured' => true,
    ]);
}

public function test_product_can_keep_its_own_slug_when_updated(): void
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

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $product = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this->put(
        route('products.update', $product),
        [
            'category_id' => $category->id,
            'name' => 'Nike Air Max Updated',

            // Même slug
            'slug' => 'nike-air-max',

            'status' => 'ACTIVE',
            'is_featured' => true,
        ]
    );

    $response->assertRedirect(
        route('products.show', $product)
    );

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Nike Air Max Updated',
        'slug' => 'nike-air-max',
        'is_featured' => true,
    ]);
}

public function test_product_cannot_use_slug_of_another_product_in_same_store(): void
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

    $this->actingAs($user);

    app(TenantContext::class)->setFromUser($user);

    $category = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productA = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Produit A',
        'slug' => 'produit-a',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $productB = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Produit B',
        'slug' => 'produit-b',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this->put(
        route('products.update', $productB),
        [
            'category_id' => $category->id,
            'name' => 'Produit B modifié',

            // Tentative de prendre le slug du produit A
            'slug' => 'produit-a',

            'status' => 'ACTIVE',
            'is_featured' => false,
        ]
    );

    $response->assertSessionHasErrors('slug');

    $this->assertDatabaseHas('products', [
        'id' => $productB->id,
        'name' => 'Produit B',
        'slug' => 'produit-b',
    ]);
}

public function test_product_cannot_be_updated_with_category_from_another_store(): void
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

    // Création de la catégorie de B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    // Création du produit dans A
    app(TenantContext::class)->setFromUser($userA);

    $categoryA = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productA = \App\Models\Product::create([
        'category_id' => $categoryA->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $response = $this
        ->actingAs($userA)
        ->put(
            route('products.update', $productA),
            [
                // Catégorie appartenant à B ❌
                'category_id' => $categoryB->id,

                'name' => 'Nike Air Max',
                'slug' => 'nike-air-max',
                'status' => 'ACTIVE',
                'is_featured' => false,
            ]
        );

    $response->assertSessionHasErrors('category_id');

    // Le produit doit toujours appartenir à sa catégorie originale.
    $this->assertDatabaseHas('products', [
        'id' => $productA->id,
        'store_id' => $storeA->id,
        'category_id' => $categoryA->id,
    ]);
}

public function test_user_without_products_update_permission_cannot_update_product(): void
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
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $product = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // Même boutique, mais aucun rôle/permission
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->put(
            route('products.update', $product),
            [
                'category_id' => $category->id,
                'name' => 'Produit piraté',
                'slug' => 'produit-pirate',
                'status' => 'INACTIVE',
                'is_featured' => true,
            ]
        );

    $response->assertForbidden();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);
}

public function test_store_owner_cannot_update_product_from_another_store(): void
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

    // Produit appartenant à la boutique B
    app(TenantContext::class)->setFromUser($userB);

    $categoryB = Category::create([
        'name' => 'Téléphones',
        'slug' => 'telephones',
        'status' => 'ACTIVE',
    ]);

    $productB = \App\Models\Product::create([
        'category_id' => $categoryB->id,
        'name' => 'iPhone Test',
        'slug' => 'iphone-test',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    // User A tente de modifier le produit de B
    app(TenantContext::class)->setFromUser($userA);

    $response = $this
        ->actingAs($userA)
        ->put(
            '/products/' . $productB->id,
            [
                'category_id' => $categoryB->id,
                'name' => 'Produit piraté',
                'slug' => 'produit-pirate',
                'status' => 'INACTIVE',
                'is_featured' => true,
            ]
        );

    $response->assertNotFound();

    // Vérification directe sans tenant scope
    $this->assertDatabaseHas('products', [
        'id' => $productB->id,
        'store_id' => $storeB->id,
        'name' => 'iPhone Test',
        'slug' => 'iphone-test',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);
}
}