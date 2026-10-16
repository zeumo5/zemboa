<?php
namespace Tests\Feature;


use App\Actions\ProductVariant\CreateProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StockMovementHistoryHttpTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshSafeDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (
            config('database.default') !== 'sqlite' ||
            config('database.connections.sqlite.database') !== ':memory:'
        ) {
            throw new RuntimeException(
                'Tests interdits hors SQLite :memory:.'
            );
        }

        $this->refreshSafeDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    private function createStore(string $slug): Store
    {
        return Store::create([
            'name' => 'Boutique ' . $slug,
            'slug' => $slug,
            'status' => 'ACTIVE',
        ]);
    }

    private function createEmployee(
        Store $store,
        string $roleCode
    ): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createMovement(
        Store $store,
        User $author,
        string $suffix
    ): StockMovement {
        app(TenantContext::class)->setFromStore($store);

        $category = Category::create([
            'name' => 'Catégorie ' . $suffix,
            'slug' => 'categorie-' . $suffix,
            'status' => 'ACTIVE',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit ' . $suffix,
            'slug' => 'produit-' . $suffix,
            'status' => 'ACTIVE',
        ]);

        $variant = app(CreateProductVariant::class)
            ->execute([
                'product_id' => $product->id,
                'sku' => 'AUDIT-' . $suffix,
                'price' => '5000.00',
                'is_default' => true,
                'status' => 'ACTIVE',
            ]);

        return StockMovement::create([
            'product_variant_id' => $variant->id,
            'type' => 'RECEIPT',
            'quantity' => 20,
            'reason' => 'Arrivage fournisseur',
            'created_by' => $author->id,
        ]);
    }

    public function test_owner_can_see_stock_history_and_author(): void
    {
        $store = $this->createStore('owner-history');
        $owner = $this->createEmployee(
            $store,
            'STORE_OWNER'
        );

        $this->actingAs($owner);

        $movement = $this->createMovement(
            $store,
            $owner,
            'owner'
        );

        $this->getJson('/stock/movements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $movement->id)
            ->assertJsonPath('data.0.type', 'RECEIPT')
            ->assertJsonPath('data.0.quantity', 20)
            ->assertJsonPath(
                'data.0.creator.name',
                $owner->name
            );
    }

    public function test_manager_can_see_own_store_history(): void
    {
        $store = $this->createStore('manager-history');

        $owner = $this->createEmployee(
            $store,
            'STORE_OWNER'
        );

        $manager = $this->createEmployee(
            $store,
            'STORE_MANAGER'
        );

        $this->actingAs($owner);

        $movement = $this->createMovement(
            $store,
            $manager,
            'manager'
        );

        $this->actingAs($manager);

        $this->getJson('/stock/movements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $movement->id)
            ->assertJsonPath(
                'data.0.creator.name',
                $manager->name
            );
    }

    public function test_cashier_and_delivery_agent_are_denied(): void
    {
        $store = $this->createStore('restricted-history');

        foreach (['CASHIER', 'DELIVERY_AGENT'] as $role) {
            $user = $this->createEmployee($store, $role);

            $this->actingAs($user);

            $this->getJson('/stock/movements')
                ->assertForbidden();
        }
    }

    public function test_store_cannot_see_another_store_history(): void
    {
        $storeA = $this->createStore('history-alpha');
        $storeB = $this->createStore('history-beta');

        $ownerA = $this->createEmployee(
            $storeA,
            'STORE_OWNER'
        );

        $ownerB = $this->createEmployee(
            $storeB,
            'STORE_OWNER'
        );

        $this->actingAs($ownerA);

        $movementA = $this->createMovement(
            $storeA,
            $ownerA,
            'alpha'
        );

        $this->actingAs($ownerB);

        $this->createMovement(
            $storeB,
            $ownerB,
            'beta'
        );

        $this->actingAs($ownerA);

        $this->getJson('/stock/movements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $movementA->id)
            ->assertJsonPath(
                'data.0.creator.name',
                $ownerA->name
            );
    }

    public function test_guest_cannot_see_stock_history(): void
    {
        $this->getJson('/stock/movements')
            ->assertUnauthorized();
    }
}
