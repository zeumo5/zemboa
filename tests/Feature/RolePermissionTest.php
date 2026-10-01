<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_store_owner_role(): void
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => 'Propriétaire de boutique',
            'code' => 'STORE_OWNER',
        ]);

        $user->roles()->attach($role);

        $this->assertTrue(
            $user->hasRole('STORE_OWNER')
        );
    }

    public function test_store_owner_can_have_product_create_permission(): void
{
    $user = User::factory()->create();

    $role = Role::create([
        'name' => 'Propriétaire de boutique',
        'code' => 'STORE_OWNER',
    ]);

    $permission = Permission::create([
        'name' => 'Créer un produit',
        'code' => 'products.create',
    ]);

    $role->permissions()->attach($permission);
    $user->roles()->attach($role);

    $this->assertTrue(
        $user->hasPermission('products.create')
    );
}

public function test_delivery_agent_cannot_create_products(): void
{
    $user = User::factory()->create();

    $deliveryAgent = Role::create([
        'name' => 'Agent de livraison',
        'code' => 'DELIVERY_AGENT',
    ]);

    $productCreate = Permission::create([
        'name' => 'Créer un produit',
        'code' => 'products.create',
    ]);

    $deliveryView = Permission::create([
        'name' => 'Voir les livraisons',
        'code' => 'deliveries.view',
    ]);

    $deliveryAgent->permissions()->attach($deliveryView);
    $user->roles()->attach($deliveryAgent);

    $this->assertFalse(
        $user->hasPermission('products.create')
    );

    $this->assertTrue(
        $user->hasPermission('deliveries.view')
    );
}

public function test_user_without_role_has_no_permission(): void
{
    $user = User::factory()->create();

    $this->assertFalse(
        $user->hasRole('STORE_OWNER')
    );

    $this->assertFalse(
        $user->hasPermission('products.create')
    );
}
}