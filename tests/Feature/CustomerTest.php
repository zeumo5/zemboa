<?php

namespace Tests\Feature;

use App\Actions\Customers\UpdateCustomer;
use App\Actions\Customers\CreateCustomer;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_is_automatically_attached_to_current_store(): void
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
            'phone' => '690000001',
            'email' => 'jean@example.com',
        ]);

        $customer->refresh();

        $this->assertSame($store->id, $customer->store_id);
        $this->assertSame('ACTIVE', $customer->status);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'store_id' => $store->id,
            'name' => 'Jean Client',
            'phone' => '690000001',
            'email' => 'jean@example.com',
            'status' => 'ACTIVE',
        ]);
    }
public function test_same_phone_can_exist_in_different_stores(): void
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

    // Création du client dans la boutique A
    app(TenantContext::class)->setFromUser($userA);

    $customerA = Customer::create([
        'name' => 'Jean Client',
        'phone' => '690000001',
    ]);

    // Même numéro, mais dans la boutique B
    app(TenantContext::class)->setFromUser($userB);

    $customerB = Customer::create([
        'name' => 'Jean Client',
        'phone' => '690000001',
    ]);

    $this->assertNotSame($customerA->id, $customerB->id);

    $this->assertSame($storeA->id, $customerA->store_id);
    $this->assertSame($storeB->id, $customerB->store_id);

    $this->assertDatabaseHas('customers', [
        'store_id' => $storeA->id,
        'phone' => '690000001',
    ]);

    $this->assertDatabaseHas('customers', [
        'store_id' => $storeB->id,
        'phone' => '690000001',
    ]);
}

public function test_same_phone_cannot_exist_twice_in_same_store(): void
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

    Customer::create([
        'name' => 'Jean Client',
        'phone' => '690000001',
    ]);

    $this->expectException(\Illuminate\Database\QueryException::class);

    Customer::create([
        'name' => 'Autre Client',
        'phone' => '690000001',
    ]);
}

public function test_customer_queries_are_isolated_by_store(): void
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

    app(TenantContext::class)->setFromUser($userA);

    $customerA = Customer::create([
        'name' => 'Client Alpha',
        'phone' => '690000001',
    ]);

    app(TenantContext::class)->setFromUser($userB);

    $customerB = Customer::create([
        'name' => 'Client Beta',
        'phone' => '690000002',
    ]);

    // On revient dans le contexte de la boutique A.
    app(TenantContext::class)->setFromUser($userA);

    $customers = Customer::query()->get();

    $this->assertTrue(
        $customers->contains('id', $customerA->id)
    );

    $this->assertFalse(
        $customers->contains('id', $customerB->id)
    );

    $this->assertCount(1, $customers);
}

public function test_store_owner_can_view_customer_from_own_store(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '690000001',
    ]);

    $this->assertTrue(
        $user->can('view', $customer)
    );
}

public function test_user_without_customers_view_permission_cannot_view_customer(): void
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

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '690000001',
    ]);

    $this->assertFalse(
        $user->can('view', $customer)
    );
}

public function test_store_owner_can_create_customer(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $this->assertTrue(
        $user->can('create', Customer::class)
    );
}

public function test_customer_cannot_be_deleted_even_by_store_owner(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '690000001',
    ]);

    $this->assertFalse(
        $user->can('delete', $customer)
    );
}

public function test_create_customer_action_normalizes_phone_and_uses_current_store(): void
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

    $customer = app(CreateCustomer::class)->execute(
        name: 'Jean Client',
        phone: '690 000 001',
        email: 'jean@example.com',
    );

    $customer->refresh();

    $this->assertSame($store->id, $customer->store_id);
    $this->assertSame('+237690000001', $customer->phone);
    $this->assertSame('Jean Client', $customer->name);
    $this->assertSame('jean@example.com', $customer->email);
    $this->assertSame('ACTIVE', $customer->status);
}

public function test_update_customer_action_updates_data_and_normalizes_phone(): void
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
        'email' => 'jean@example.com',
    ]);

    $updatedCustomer = app(UpdateCustomer::class)->execute(
        customer: $customer,
        name: 'Jean Paul',
        phone: '691 000 002',
        email: 'paul@example.com',
        status: 'INACTIVE',
    );

    $this->assertSame('Jean Paul', $updatedCustomer->name);
    $this->assertSame('+237691000002', $updatedCustomer->phone);
    $this->assertSame('paul@example.com', $updatedCustomer->email);
    $this->assertSame('INACTIVE', $updatedCustomer->status);

    // Le client doit rester dans sa boutique d'origine.
    $this->assertSame($store->id, $updatedCustomer->store_id);
}

public function test_store_owner_can_show_customer_from_own_store_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('customers.show', $customer));

    $response
        ->assertOk()
        ->assertJson([
            'id' => $customer->id,
            'store_id' => $store->id,
            'name' => 'Jean Client',
            'phone' => '+237690000001',
        ]);
}

public function test_customer_from_another_store_returns_404_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $userA->roles()->attach($role);

    // Création du client dans la boutique B
    app(TenantContext::class)->setFromUser($userB);

    $customerB = Customer::create([
        'name' => 'Client Beta',
        'phone' => '+237690000002',
    ]);

    // On revient dans le contexte de la boutique A
    app(TenantContext::class)->setFromUser($userA);

    $response = $this
        ->actingAs($userA)
        ->get(route('customers.show', $customerB->id));

    $response->assertNotFound();
}

public function test_user_without_customers_view_permission_gets_403_via_http(): void
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

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('customers.show', $customer));

    $response->assertForbidden();
}

public function test_store_owner_can_create_customer_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->post(route('customers.store'), [
            'name' => 'Jean Client',
            'phone' => '690 000 001',
            'email' => 'jean@example.com',
        ]);

    $customer = Customer::query()
        ->where('phone', '+237690000001')
        ->firstOrFail();

    $response->assertRedirect(
        route('customers.show', $customer)
    );

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'store_id' => $store->id,
        'name' => 'Jean Client',
        'phone' => '+237690000001',
        'email' => 'jean@example.com',
        'status' => 'ACTIVE',
    ]);
}

public function test_user_without_customers_create_permission_cannot_create_customer_via_http(): void
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

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->post(route('customers.store'), [
            'name' => 'Jean Client',
            'phone' => '690000001',
            'email' => 'jean@example.com',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseMissing('customers', [
        'store_id' => $store->id,
        'phone' => '+237690000001',
    ]);
}

public function test_store_owner_can_update_customer_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
        'email' => 'jean@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('customers.update', $customer), [
            'name' => 'Jean Paul',
            'phone' => '691 000 002',
            'email' => 'paul@example.com',
            'status' => 'INACTIVE',
        ]);

    $response->assertRedirect(
        route('customers.show', $customer)
    );

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'store_id' => $store->id,
        'name' => 'Jean Paul',
        'phone' => '+237691000002',
        'email' => 'paul@example.com',
        'status' => 'INACTIVE',
    ]);
}

public function test_user_without_customers_update_permission_cannot_update_customer_via_http(): void
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

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
        'email' => 'jean@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('customers.update', $customer), [
            'name' => 'Nom Modifié',
            'phone' => '691000002',
            'email' => 'modifie@example.com',
            'status' => 'INACTIVE',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'name' => 'Jean Client',
        'phone' => '+237690000001',
        'email' => 'jean@example.com',
        'status' => 'ACTIVE',
    ]);
}

public function test_customer_from_another_store_cannot_be_updated_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $userA->roles()->attach($role);

    // Création du client dans Boutique B
    app(TenantContext::class)->setFromUser($userB);

    $customerB = Customer::create([
        'name' => 'Client Beta',
        'phone' => '+237690000002',
        'email' => 'beta@example.com',
    ]);

    // Retour dans le contexte de Boutique A
    app(TenantContext::class)->setFromUser($userA);

    $response = $this
        ->actingAs($userA)
        ->patch(route('customers.update', $customerB->id), [
            'name' => 'Tentative Modification',
            'phone' => '691000003',
            'email' => 'hack@example.com',
            'status' => 'INACTIVE',
        ]);

    $response->assertNotFound();

    // Vérifie que les données de Boutique B n'ont pas été modifiées.
    app(TenantContext::class)->setFromUser($userB);

    $customerB->refresh();

    $this->assertSame('Client Beta', $customerB->name);
    $this->assertSame('+237690000002', $customerB->phone);
    $this->assertSame('beta@example.com', $customerB->email);
    $this->assertSame('ACTIVE', $customerB->status);
}

public function test_invalid_phone_is_rejected_when_creating_customer_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $response = $this
        ->actingAs($user)
        ->from(route('customers.index'))
        ->post(route('customers.store'), [
            'name' => 'Jean Client',
            'phone' => '12345',
            'email' => 'jean@example.com',
        ]);

    $response
        ->assertRedirect(route('customers.index'))
        ->assertSessionHasErrors('phone');

    $this->assertDatabaseMissing('customers', [
        'store_id' => $store->id,
        'name' => 'Jean Client',
    ]);
}

public function test_invalid_phone_is_rejected_when_updating_customer_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
        'email' => 'jean@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('customers.show', $customer))
        ->patch(route('customers.update', $customer), [
            'name' => 'Jean Modifié',
            'phone' => '12345',
            'email' => 'modifie@example.com',
            'status' => 'INACTIVE',
        ]);

    $response
        ->assertRedirect(route('customers.show', $customer))
        ->assertSessionHasErrors('phone');

    $customer->refresh();

    $this->assertSame('Jean Client', $customer->name);
    $this->assertSame('+237690000001', $customer->phone);
    $this->assertSame('jean@example.com', $customer->email);
    $this->assertSame('ACTIVE', $customer->status);
}

public function test_duplicate_normalized_phone_is_rejected_when_creating_customer_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    // Le premier client possède déjà ce numéro sous sa forme normalisée.
    Customer::create([
        'name' => 'Premier Client',
        'phone' => '+237690000001',
    ]);

    // On essaie de créer un autre client avec une autre écriture
    // du même numéro.
    $response = $this
        ->actingAs($user)
        ->from(route('customers.index'))
        ->post(route('customers.store'), [
            'name' => 'Deuxième Client',
            'phone' => '690 000 001',
            'email' => 'deuxieme@example.com',
        ]);

    $response
        ->assertRedirect(route('customers.index'))
        ->assertSessionHasErrors('phone');

    $this->assertSame(
        1,
        Customer::query()
            ->where('phone', '+237690000001')
            ->count()
    );
}

public function test_customer_can_keep_own_phone_when_updating_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $customer = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
        'email' => 'jean@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('customers.update', $customer), [
            'name' => 'Jean Modifié',
            'phone' => '690 000 001',
            'email' => 'jean@example.com',
            'status' => 'ACTIVE',
        ]);

    $response->assertRedirect(
        route('customers.show', $customer)
    );

    $response->assertSessionDoesntHaveErrors('phone');

    $customer->refresh();

    $this->assertSame('Jean Modifié', $customer->name);
    $this->assertSame('+237690000001', $customer->phone);
}

public function test_customer_cannot_take_another_customers_phone_when_updating_via_http(): void
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

    $role = \App\Models\Role::where('code', 'STORE_OWNER')->firstOrFail();
    $user->roles()->attach($role);

    app(TenantContext::class)->setFromUser($user);

    $jean = Customer::create([
        'name' => 'Jean Client',
        'phone' => '+237690000001',
    ]);

    $paul = Customer::create([
        'name' => 'Paul Client',
        'phone' => '+237691000002',
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('customers.show', $jean))
        ->patch(route('customers.update', $jean), [
            'name' => 'Jean Client',
            // Même numéro que Paul, mais écrit différemment.
            'phone' => '691 000 002',
            'email' => null,
            'status' => 'ACTIVE',
        ]);

    $response
        ->assertRedirect(route('customers.show', $jean))
        ->assertSessionHasErrors('phone');

    $jean->refresh();

    $this->assertSame('+237690000001', $jean->phone);
    $this->assertSame('Jean Client', $jean->name);
}
    
}