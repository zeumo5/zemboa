<?php

namespace Tests\Feature;

use App\Actions\ProductVariant\AttachAttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Actions\AttributeValue\CreateAttributeValue;
use Illuminate\Validation\ValidationException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributeTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_has_values_and_value_belongs_to_attribute(): void
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

        $attribute = Attribute::create([
            'name' => 'Couleur',
            'slug' => 'couleur',
            'status' => 'ACTIVE',
        ]);

        $black = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => 'Noir',
        ]);

        $white = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => 'Blanc',
        ]);

        // AttributeValue → Attribute
        $this->assertTrue(
            $black->attribute->is($attribute)
        );

        // Attribute → AttributeValues
        $this->assertCount(2, $attribute->values);

        $this->assertTrue(
            $attribute->values->contains($black)
        );

        $this->assertTrue(
            $attribute->values->contains($white)
        );

        // Vérification du tenant automatique
        $this->assertSame(
            $store->id,
            $attribute->store_id
        );

        $this->assertSame(
            $store->id,
            $black->store_id
        );
    }

    public function test_create_attribute_value_rejects_attribute_from_another_store(): void
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

    // Création de l'attribut dans la Boutique B
    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    app(TenantContext::class)->setFromUser($userB);

    $attributeB = Attribute::create([
        'name' => 'Couleur',
        'slug' => 'couleur',
        'status' => 'ACTIVE',
    ]);

    // Boutique A devient le tenant actif
    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    app(TenantContext::class)->setFromUser($userA);

    try {
        app(CreateAttributeValue::class)->execute([
            'attribute_id' => $attributeB->id,
            'value' => 'Noir',
        ]);

        $this->fail(
            'CreateAttributeValue aurait dû refuser un attribut appartenant à une autre boutique.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'attribute_id',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing('attribute_values', [
        'store_id' => $storeA->id,
        'attribute_id' => $attributeB->id,
    ]);
}

public function test_product_variant_can_have_attribute_values(): void
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
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-BLK-42',
        'price' => 45000,
        'status' => 'ACTIVE',
        'is_default' => false,
    ]);

    $color = Attribute::create([
        'name' => 'Couleur',
        'slug' => 'couleur',
        'status' => 'ACTIVE',
    ]);

    $black = AttributeValue::create([
        'attribute_id' => $color->id,
        'value' => 'Noir',
    ]);

    $size = Attribute::create([
        'name' => 'Taille',
        'slug' => 'taille',
        'status' => 'ACTIVE',
    ]);

    $size42 = AttributeValue::create([
        'attribute_id' => $size->id,
        'value' => '42',
    ]);

    $variant->attributeValues()->attach([
        $black->id,
        $size42->id,
    ]);

    $variant->refresh();

    $this->assertCount(2, $variant->attributeValues);

    $this->assertTrue(
        $variant->attributeValues->contains($black)
    );

    $this->assertTrue(
        $variant->attributeValues->contains($size42)
    );

    $this->assertTrue(
        $black->productVariants->contains($variant)
    );
}

public function test_cannot_attach_attribute_value_from_another_store_to_variant(): void
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

    // -------------------------
    // Boutique A
    // -------------------------

    $userA = User::factory()->create([
        'store_id' => $storeA->id,
    ]);

    app(TenantContext::class)->setFromUser($userA);

    $categoryA = Category::create([
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $productA = Product::create([
        'category_id' => $categoryA->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variantA = ProductVariant::create([
        'product_id' => $productA->id,
        'sku' => 'NIKE-A-001',
        'price' => 45000,
        'status' => 'ACTIVE',
        'is_default' => false,
    ]);

    // -------------------------
    // Boutique B
    // -------------------------

    $userB = User::factory()->create([
        'store_id' => $storeB->id,
    ]);

    app(TenantContext::class)->setFromUser($userB);

    $colorB = Attribute::create([
        'name' => 'Couleur',
        'slug' => 'couleur',
        'status' => 'ACTIVE',
    ]);

    $blackB = AttributeValue::create([
        'attribute_id' => $colorB->id,
        'value' => 'Noir',
    ]);

    // -------------------------
    // Retour à Boutique A
    // -------------------------

    app(TenantContext::class)->setFromUser($userA);

    try {
        app(AttachAttributeValue::class)->execute(
            $variantA->id,
            $blackB->id
        );

        $this->fail(
            'Une valeur d’attribut d’une autre boutique ne doit pas pouvoir être attachée.'
        );
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey(
            'attribute_value_id',
            $exception->errors()
        );
    }

    $this->assertDatabaseMissing(
        'product_variant_attribute_value',
        [
            'product_variant_id' => $variantA->id,
            'attribute_value_id' => $blackB->id,
        ]
    );
}

public function test_variant_cannot_have_two_values_for_same_attribute(): void
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
        'name' => 'Chaussures',
        'slug' => 'chaussures',
        'status' => 'ACTIVE',
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Nike Air Max',
        'slug' => 'nike-air-max',
        'status' => 'ACTIVE',
        'is_featured' => false,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'NIKE-001',
        'price' => 45000,
        'status' => 'ACTIVE',
        'is_default' => false,
    ]);

    $color = Attribute::create([
        'name' => 'Couleur',
        'slug' => 'couleur',
        'status' => 'ACTIVE',
    ]);

    $black = AttributeValue::create([
        'attribute_id' => $color->id,
        'value' => 'Noir',
    ]);

    $white = AttributeValue::create([
        'attribute_id' => $color->id,
        'value' => 'Blanc',
    ]);

    $action = app(AttachAttributeValue::class);

    // Première couleur : autorisée
    $action->execute($variant->id, $black->id);

    $this->expectException(ValidationException::class);

    // Deuxième valeur du même attribut : doit être refusée
    $action->execute($variant->id, $white->id);
}
}