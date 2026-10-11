<?php

namespace Tests\Feature;

use App\Models\StockReservation;
use App\Actions\Orders\CreateOrder;
use App\Actions\ProductVariant\CreateProductVariant;
use App\Actions\Stock\ReceiveStock;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Support\TenantContext;
use Symfony\Component\Process\Process;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfirmPaymentConcurrencyTest extends TestCase
{
    public function test_two_independent_mysql_connections(): void
    {
        // Ce test nécessite MySQL.
        // Les tests habituels SQLite doivent l'ignorer.
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped(
                'Test réservé à MySQL.'
            );
        }

        // Vérifier la configuration AVANT toute connexion.
        $this->assertSame(
            'zemboa_concurrency_test',
            config('database.connections.mysql.database')
        );

        $this->assertSame(
            'zemboa_test',
            config('database.connections.mysql.username')
        );

        // Première connexion MySQL.
        $connectionA = DB::connection('mysql');

        // Créer une deuxième connexion avec les mêmes
        // paramètres, mais une connexion PDO distincte.
        config([
            'database.connections.mysql_concurrency_second'
            => config('database.connections.mysql'),
        ]);

        $connectionB = DB::connection(
            'mysql_concurrency_second'
        );

        try {
            // Vérifier que les connexions PDO sont distinctes.
            $this->assertNotSame(
                $connectionA->getPdo(),
                $connectionB->getPdo()
            );

            // Vérifier la base et le compte réellement utilisés.
            foreach ([$connectionA, $connectionB] as $connection) {
                $result = $connection->selectOne(
                    'SELECT DATABASE() AS db,
                            CURRENT_USER() AS account'
                );

                $this->assertSame(
                    'zemboa_concurrency_test',
                    $result->db
                );

                $this->assertSame(
                    'zemboa_test@127.0.0.1',
                    $result->account
                );
            }
        } finally {
            // Fermer la deuxième connexion après le test.
            DB::purge('mysql_concurrency_second');
        }
    }
    public function test_mysql_row_lock_blocks_second_connection(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Test réservé à MySQL.');
        }

        // Vérifier la configuration avant toute écriture.
        $this->assertSame(
            'zemboa_concurrency_test',
            config('database.connections.mysql.database')
        );

        $this->assertSame(
            'zemboa_test',
            config('database.connections.mysql.username')
        );

        config([
            'database.connections.mysql_lock_second'
            => config('database.connections.mysql'),
        ]);

        $connectionA = DB::connection('mysql');
        $connectionB = DB::connection('mysql_lock_second');

        $storeId = null;

        try {
            // Vérifier les deux connexions réelles.
            foreach ([$connectionA, $connectionB] as $connection) {
                $identity = $connection->selectOne(
                    'SELECT DATABASE() AS db, CURRENT_USER() AS account'
                );

                $this->assertSame(
                    'zemboa_concurrency_test',
                    $identity->db
                );

                $this->assertSame(
                    'zemboa_test@127.0.0.1',
                    $identity->account
                );
            }

            // Créer une boutique temporaire dans la base de test.
            $storeId = $connectionA->table('stores')->insertGetId([
                'name' => 'Boutique Test Verrou',
                'slug' => 'test-lock-' . Str::uuid(),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Limiter l'attente de B à une seconde.
            $connectionB->statement(
                'SET SESSION innodb_lock_wait_timeout = 1'
            );

            // Connexion A : verrouiller la boutique.
            $connectionA->beginTransaction();

            $lockedStore = $connectionA->table('stores')
                ->where('id', $storeId)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($lockedStore);

            // Connexion B : tenter le même verrouillage.
            $connectionB->beginTransaction();

            try {
                $connectionB->table('stores')
                    ->where('id', $storeId)
                    ->lockForUpdate()
                    ->first();

                $this->fail(
                    'La connexion B aurait dû attendre le verrou de A.'
                );
            } catch (QueryException $exception) {
                // MySQL 1205 : délai d'attente du verrou dépassé.
                $this->assertSame(
                    1205,
                    (int) ($exception->errorInfo[1] ?? 0)
                );
            }

            // Libérer les transactions.
            $connectionB->rollBack();
            $connectionA->rollBack();

            // Vérifier que la ligne reste accessible.
            $this->assertNotNull(
                $connectionB->table('stores')
                    ->where('id', $storeId)
                    ->first()
            );
        } finally {
            // Nettoyage, même si le test échoue.
            if ($connectionB->transactionLevel() > 0) {
                $connectionB->rollBack();
            }

            if ($connectionA->transactionLevel() > 0) {
                $connectionA->rollBack();
            }

            if ($storeId !== null) {
                $connectionA->table('stores')
                    ->where('id', $storeId)
                    ->delete();
            }

            DB::purge('mysql_lock_second');
        }
    }


    public function test_two_php_processes_can_run_independently(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped(
                'Test réservé à MySQL.'
            );
        }

        $this->assertSame(
            'zemboa_concurrency_test',
            config('database.connections.mysql.database')
        );

        // Chaque processus attend une seconde,
        // puis affiche son identifiant.
        $script = 'usleep(1000000); echo getmypid();';

        $processA = new Process([PHP_BINARY, '-r', $script]);
        $processB = new Process([PHP_BINARY, '-r', $script]);

        try {
            // Démarrer les deux processus sans attendre
            // la fin du premier.
            $processA->start();
            $processB->start();

            // Attendre leur terminaison.
            $processA->wait();
            $processB->wait();

            // Vérifier leur réussite.
            $this->assertTrue($processA->isSuccessful());
            $this->assertTrue($processB->isSuccessful());

            // Vérifier leurs identifiants distincts.
            $pidA = trim($processA->getOutput());
            $pidB = trim($processB->getOutput());

            $this->assertNotEmpty($pidA);
            $this->assertNotEmpty($pidB);
            $this->assertNotSame($pidA, $pidB);
        } finally {
            if ($processA->isRunning()) {
                $processA->stop();
            }

            if ($processB->isRunning()) {
                $processB->stop();
            }
        }
    }


    private function assertSafeConcurrencyDatabase(): void
    {
        // Vérifier l'environnement Laravel.
        $this->assertTrue(
            app()->environment('testing'),
            'Ce test doit utiliser l’environnement testing.'
        );

        // Vérifier la configuration MySQL.
        $this->assertSame(
            'mysql',
            config('database.default')
        );

        $this->assertSame(
            'zemboa_concurrency_test',
            config('database.connections.mysql.database')
        );

        $this->assertSame(
            'zemboa_test',
            config('database.connections.mysql.username')
        );

        // Vérifier la connexion réellement établie.
        $identity = DB::selectOne(
            'SELECT DATABASE() AS db,
                CURRENT_USER() AS account'
        );

        $this->assertSame(
            'zemboa_concurrency_test',
            $identity->db
        );

        $this->assertSame(
            'zemboa_test@127.0.0.1',
            $identity->account
        );
    }


    private function createPaymentConcurrencyScenario(): array
    {
        // Protection obligatoire avant toute écriture.
        $this->assertSafeConcurrencyDatabase();

        $store = Store::create([
            'name' => 'Boutique Test Concurrence',
            'slug' => 'test-concurrency-' . Str::uuid(),
            'status' => 'ACTIVE',
        ]);

        $cashier = User::factory()->create([
            'store_id' => $store->id,
        ]);

        // Créer un rôle propre à ce scénario.
        $role = Role::create([
            'code' => 'TEST_CONC_' . Str::random(12),
            'name' => 'Caissier test concurrence',
        ]);

        $permission = Permission::firstOrCreate(
            ['code' => 'payments.confirm'],
            ['name' => 'Confirmer un paiement']
        );

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $cashier->roles()->syncWithoutDetaching([
            $role->id,
        ]);

        $this->actingAs($cashier);

        app(TenantContext::class)->setFromUser($cashier);

        $customer = Customer::create([
            'name' => 'Client Test Concurrence',
            'phone' => '+237690000099',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-CONC-' . Str::random(12),
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Test Concurrence',
            'customer_phone' => '+237690000099',
            'subtotal' => '10000.00',
            'total' => '10000.00',
        ]);

        $paymentA = Payment::create([
            'order_id' => $order->id,
            'amount' => '7000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '7000.00',
            'change_given' => '0.00',
        ]);

        $paymentB = Payment::create([
            'order_id' => $order->id,
            'amount' => '5000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '5000.00',
            'change_given' => '0.00',
        ]);

        return compact(
            'store',
            'cashier',
            'role',
            'permission',
            'customer',
            'order',
            'paymentA',
            'paymentB'
        );
    }


    public function test_parallel_confirmations_cannot_exceed_order_total(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped(
                'Ce test de concurrence nécessite MySQL.'
            );
        }
        // Vérification obligatoire avant toute écriture.
        $this->assertSafeConcurrencyDatabase();

        $connection = DB::connection('mysql');

        // Les données sont validées ensemble pour être
        // visibles par les deux processus indépendants.
        $scenario = $connection->transaction(
            fn() => $this->createPaymentConcurrencyScenario()
        );

        $order = $scenario['order'];
        $cashier = $scenario['cashier'];

        $processA = null;
        $processB = null;

        try {
            $dueDate = now()->addDays(3)
                ->format('Y-m-d H:i:s');

            $makeProcess = function (int $paymentId) use (
                $cashier,
                $dueDate
            ): Process {
                $process = new Process([
                    PHP_BINARY,
                    'artisan',
                    '--env=testing',
                    'test:payment-concurrency-worker',
                    (string) $paymentId,
                    (string) $cashier->id,
                    '--balance-due-at=' . $dueDate,
                ], base_path());

                $process->setTimeout(30);

                return $process;
            };

            $processA = $makeProcess(
                $scenario['paymentA']->id
            );

            $processB = $makeProcess(
                $scenario['paymentB']->id
            );

            // Bloquer temporairement la commande.
            // Cela force les deux processus à attendre
            // avant de pouvoir confirmer les paiements.
            $connection->beginTransaction();

            $lockedOrder = $connection->table('orders')
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($lockedOrder);

            // Démarrer les processus indépendamment.
            $processA->start();
            $processB->start();

            // Leur laisser le temps de démarrer.
            usleep(2000000);

            // Aucun ne doit avoir terminé tant que
            // notre transaction verrouille la commande.
            $this->assertTrue(
                $processA->isRunning(),
                'Le processus A s’est terminé prématurément : '
                    . $processA->getOutput()
                    . $processA->getErrorOutput()
            );

            $this->assertTrue(
                $processB->isRunning(),
                'Le processus B s’est terminé prématurément : '
                    . $processB->getOutput()
                    . $processB->getErrorOutput()
            );

            // Libérer la commande : les processus
            // peuvent maintenant se concurrencer.
            $connection->rollBack();

            $processA->wait();
            $processB->wait();

            $outputs = [
                $processA->getOutput(),
                $processB->getOutput(),
            ];

            $confirmed = 0;
            $rejected = 0;

            foreach ($outputs as $output) {
                if (str_contains($output, 'RESULT=CONFIRMED')) {
                    $confirmed++;
                }

                if (str_contains($output, 'RESULT=REJECTED')) {
                    $rejected++;
                }
            }

            // Exactement un succès et un refus.
            $this->assertSame(1, $confirmed, implode("\n", $outputs));
            $this->assertSame(1, $rejected, implode("\n", $outputs));

            // Vérifier directement les données MySQL.
            $payments = $connection->table('payments')
                ->where('order_id', $order->id)
                ->get();

            $this->assertSame(2, $payments->count());

            $confirmedPayments = $payments
                ->where('status', 'CONFIRMED');

            $this->assertSame(1, $confirmedPayments->count());

            $confirmedAmount = $confirmedPayments
                ->sum(fn($payment) => (int) $payment->amount);

            $this->assertContains(
                $confirmedAmount,
                [5000, 7000]
            );

            $this->assertSame(
                1,
                $payments->where('status', 'PENDING')->count()
            );

            $this->assertSame(
                'PARTIALLY_PAID',
                $connection->table('orders')
                    ->where('id', $order->id)
                    ->value('payment_status')
            );
        } finally {
            // Libérer un éventuel verrou restant.
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            // Ne jamais nettoyer pendant qu'un
            // processus travaille encore.
            foreach ([$processA, $processB] as $process) {
                if ($process !== null && $process->isRunning()) {
                    $process->stop(1);
                }
            }

            // Supprimer uniquement les données créées
            // pour ce scénario de test.
            $connection->table('payments')
                ->where('order_id', $order->id)->delete();

            $connection->table('orders')
                ->where('id', $order->id)->delete();

            $connection->table('customers')
                ->where('id', $scenario['customer']->id)->delete();

            $connection->table('role_user')
                ->where('user_id', $cashier->id)->delete();

            $connection->table('permission_role')
                ->where('role_id', $scenario['role']->id)->delete();

            $connection->table('users')
                ->where('id', $cashier->id)->delete();

            $connection->table('roles')
                ->where('id', $scenario['role']->id)->delete();

            $connection->table('stores')
                ->where('id', $scenario['store']->id)->delete();
        }
    }


    private function createStockConcurrencyScenario(): array
    {
        // Vérifier la base avant toute écriture.
        $this->assertSafeConcurrencyDatabase();

        // 1. Créer une boutique de test.
        $store = Store::create([
            'name' => 'Boutique Test Stock Concurrence',
            'slug' => 'test-stock-' . Str::uuid(),
            'status' => 'ACTIVE',
        ]);

        // 2. Créer un caissier.
        $cashier = User::factory()->create([
            'store_id' => $store->id,
        ]);

        // 3. Préparer ses permissions.
        $role = Role::create([
            'code' => 'TEST_STOCK_' . Str::random(12),
            'name' => 'Caissier test stock',
        ]);

        $permission = Permission::firstOrCreate(
            ['code' => 'payments.confirm'],
            ['name' => 'Confirmer un paiement']
        );

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $cashier->roles()->syncWithoutDetaching([
            $role->id,
        ]);

        $this->actingAs($cashier);
        app(TenantContext::class)->setFromUser($cashier);

        // 4. Créer une catégorie.
        $category = Category::create([
            'name' => 'Catégorie Test Stock',
            'slug' => 'test-category-' . Str::uuid(),
            'status' => 'ACTIVE',
        ]);

        // 5. Créer un produit.
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produit Test Stock',
            'slug' => 'test-product-' . Str::uuid(),
            'status' => 'ACTIVE',
        ]);

        // 6. Créer une variante à 5 000 FCFA.
        $variant = app(CreateProductVariant::class)->execute([
            'product_id' => $product->id,
            'sku' => 'TEST-STOCK-' . Str::random(12),
            'price' => '5000.00',
            'is_default' => true,
            'status' => 'ACTIVE',
        ]);

        // 7. Ajouter 10 unités au stock.
        app(ReceiveStock::class)->execute(
            productVariantId: $variant->id,
            quantity: 10,
            userId: $cashier->id,
            reason: 'Stock initial du test de concurrence'
        );

        // 8. Créer une vraie commande avec réservation.
        $customerPhone = '+23769' . random_int(
            1000000,
            9999999
        );

        $order = app(CreateOrder::class)->execute(
            customerName: 'Client Test Stock',
            customerPhone: $customerPhone,
            customerEmail: null,
            fulfillmentType: 'PICKUP',
            items: [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        );

        // 9. Vérifier le montant et la réservation.
        $this->assertSame('10000.00', $order->total);

        $reservation = StockReservation::query()
            ->where('reference_type', 'ORDER')
            ->where('reference_id', $order->id)
            ->firstOrFail();

        $this->assertSame('ACTIVE', $reservation->status);
        $this->assertSame(2, $reservation->quantity);

        // 10. Créer deux paiements complets concurrents.
        $paymentA = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '10000.00',
            'change_given' => '0.00',
        ]);

        $paymentB = Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'status' => 'PENDING',
            'cash_received' => '10000.00',
            'change_given' => '0.00',
        ]);

        return compact(
            'store',
            'cashier',
            'role',
            'permission',
            'category',
            'product',
            'variant',
            'order',
            'reservation',
            'paymentA',
            'paymentB'
        );
    }


    private function cleanupStockConcurrencyScenario(array $scenario): void
    {
        // Ne jamais effectuer le nettoyage
        // sur la base principale de ZEMBOA.
        $this->assertSafeConcurrencyDatabase();

        $db = DB::connection('mysql');

        $storeId = $scenario['store']->id;
        $cashierId = $scenario['cashier']->id;
        $roleId = $scenario['role']->id;
        $orderId = $scenario['order']->id;
        $customerId = $scenario['order']->customer_id;
        $variantId = $scenario['variant']->id;
        $productId = $scenario['product']->id;
        $categoryId = $scenario['category']->id;

        // Nettoyage atomique : tout réussir ou tout annuler.
        $db->transaction(function () use (
            $db,
            $storeId,
            $cashierId,
            $roleId,
            $orderId,
            $customerId,
            $variantId,
            $productId,
            $categoryId
        ) {
            // 1. Paiements et articles de la commande.
            $db->table('payments')
                ->where('order_id', $orderId)
                ->where('store_id', $storeId)
                ->delete();

            $db->table('order_items')
                ->where('order_id', $orderId)
                ->delete();

            // 2. Réservations associées à la commande.
            $db->table('stock_reservations')
                ->where('store_id', $storeId)
                ->where('reference_type', 'ORDER')
                ->where('reference_id', $orderId)
                ->delete();

            // 3. Supprimer la commande.
            $db->table('orders')
                ->where('id', $orderId)
                ->where('store_id', $storeId)
                ->delete();

            // 4. Historique et niveaux de stock.
            $db->table('stock_movements')
                ->where('store_id', $storeId)
                ->where('product_variant_id', $variantId)
                ->delete();

            $db->table('stock_levels')
                ->where('store_id', $storeId)
                ->where('product_variant_id', $variantId)
                ->delete();

            // 5. Associations de variantes.
            $db->table('product_variant_attribute_value')
                ->where('product_variant_id', $variantId)
                ->delete();

            // 6. Variante, produit et catégorie.
            $db->table('product_variants')
                ->where('id', $variantId)
                ->where('store_id', $storeId)
                ->delete();

            $db->table('product_images')
                ->where('product_id', $productId)
                ->where('store_id', $storeId)
                ->delete();

            $db->table('products')
                ->where('id', $productId)
                ->where('store_id', $storeId)
                ->delete();

            $db->table('categories')
                ->where('id', $categoryId)
                ->where('store_id', $storeId)
                ->delete();

            // 7. Client temporaire.
            $db->table('customers')
                ->where('id', $customerId)
                ->where('store_id', $storeId)
                ->delete();

            // 8. Associations du caissier.
            $db->table('role_user')
                ->where('user_id', $cashierId)
                ->where('role_id', $roleId)
                ->delete();

            $db->table('permission_role')
                ->where('role_id', $roleId)
                ->delete();

            // 9. Caissier et rôle temporaires.
            $db->table('users')
                ->where('id', $cashierId)
                ->where('store_id', $storeId)
                ->delete();

            $db->table('roles')
                ->where('id', $roleId)
                ->delete();

            // 10. Boutique temporaire.
            $db->table('stores')
                ->where('id', $storeId)
                ->delete();
        });
    }


    public function test_parallel_full_payments_convert_stock_only_once(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped(
                'Ce test de concurrence nécessite MySQL.'
            );
        }
        // Vérifier la base avant toute opération.
        $this->assertSafeConcurrencyDatabase();

        $db = DB::connection('mysql');

        $scenario = null;
        $processA = null;
        $processB = null;

        try {
            // Créer les données dans une transaction.
            // Après commit, les processus pourront les lire.
            $scenario = $db->transaction(
                fn() => $this->createStockConcurrencyScenario()
            );

            $orderId = $scenario['order']->id;
            $cashierId = $scenario['cashier']->id;
            $variantId = $scenario['variant']->id;
            $reservationId = $scenario['reservation']->id;

            // Vérifier le stock avant les paiements.
            $initialStock = $db->table('stock_levels')
                ->where('product_variant_id', $variantId)
                ->first();

            $this->assertNotNull($initialStock);
            $this->assertSame(10, (int) $initialStock->physical_quantity);
            $this->assertSame(2, (int) $initialStock->reserved_quantity);

            // Préparer les deux processus PHP indépendants.
            $makeProcess = function (int $paymentId) use (
                $cashierId
            ): Process {
                $process = new Process([
                    PHP_BINARY,
                    'artisan',
                    '--env=testing',
                    'test:payment-concurrency-worker',
                    (string) $paymentId,
                    (string) $cashierId,
                ], base_path());

                $process->setTimeout(30);

                return $process;
            };

            $processA = $makeProcess($scenario['paymentA']->id);
            $processB = $makeProcess($scenario['paymentB']->id);

            // Verrouiller la commande temporairement.
            $db->beginTransaction();

            $lockedOrder = $db->table('orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($lockedOrder);

            // Démarrer les deux confirmations.
            $processA->start();
            $processB->start();

            // Laisser les processus atteindre le verrou.
            usleep(2000000);

            $this->assertTrue(
                $processA->isRunning(),
                'Processus A terminé trop tôt : '
                    . $processA->getOutput()
                    . $processA->getErrorOutput()
            );

            $this->assertTrue(
                $processB->isRunning(),
                'Processus B terminé trop tôt : '
                    . $processB->getOutput()
                    . $processB->getErrorOutput()
            );

            // Libérer le verrou pour laisser les
            // confirmations se concurrencer.
            $db->rollBack();

            $processA->wait();
            $processB->wait();

            $outputs = [
                $processA->getOutput()
                    . $processA->getErrorOutput(),
                $processB->getOutput()
                    . $processB->getErrorOutput(),
            ];

            // Un seul processus doit réussir.
            $confirmedCount = 0;
            $rejectedCount = 0;

            foreach ($outputs as $output) {
                if (str_contains($output, 'RESULT=CONFIRMED')) {
                    $confirmedCount++;
                }

                if (str_contains($output, 'RESULT=REJECTED')) {
                    $rejectedCount++;
                }
            }

            $this->assertSame(
                1,
                $confirmedCount,
                implode("\n", $outputs)
            );

            $this->assertSame(
                1,
                $rejectedCount,
                implode("\n", $outputs)
            );

            // Vérifier les paiements enregistrés dans MySQL.
            $payments = $db->table('payments')
                ->where('order_id', $orderId)
                ->get();

            $this->assertSame(2, $payments->count());

            $this->assertSame(
                1,
                $payments->where('status', 'CONFIRMED')->count()
            );

            $this->assertSame(
                1,
                $payments->where('status', 'PENDING')->count()
            );

            $this->assertEquals(
                10000,
                $payments->where('status', 'CONFIRMED')
                    ->sum('amount')
            );

            // La commande doit être entièrement payée.
            $this->assertSame(
                'PAID',
                $db->table('orders')
                    ->where('id', $orderId)
                    ->value('payment_status')
            );

            // Vérifier la réservation de stock.
            $reservation = $db->table('stock_reservations')
                ->where('id', $reservationId)
                ->first();

            $this->assertNotNull($reservation);
            $this->assertSame('CONVERTED', $reservation->status);

            // Vérifier les quantités physiques et réservées.
            $stock = $db->table('stock_levels')
                ->where('product_variant_id', $variantId)
                ->first();

            $this->assertNotNull($stock);

            $this->assertSame(
                8,
                (int) $stock->physical_quantity
            );

            $this->assertSame(
                0,
                (int) $stock->reserved_quantity
            );

            // Vérifier qu'une seule vente existe.
            $sales = $db->table('stock_movements')
                ->where('product_variant_id', $variantId)
                ->where('type', 'SALE')
                ->get();

            $this->assertSame(1, $sales->count());

            $this->assertSame(
                2,
                (int) $sales->first()->quantity
            );
        } finally {
            // Libérer tout verrou restant.
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            // Arrêter les processus avant le nettoyage.
            foreach ([$processA, $processB] as $process) {
                if ($process !== null && $process->isRunning()) {
                    $process->stop(1);
                }
            }

            // Nettoyer uniquement ce scénario.
            if ($scenario !== null) {
                $this->cleanupStockConcurrencyScenario($scenario);
            }
        }
    }
}
