<?php

namespace Tests\Feature;


use App\Actions\Payments\ResolveMobileMoneyReconciliationCase;
use App\Models\Customer;
use App\Models\MobileMoneyReconciliationCase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveMobileMoneyReconciliationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolution_and_event_are_rolled_back_together(): void
    {


        $this->assertSame('testing', app()->environment());

        $this->assertSame('sqlite', DB::getDriverName());

        $this->assertSame(
            ':memory:',
            DB::connection()->getDatabaseName()
        );


        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Rollback Test',
            'slug' => 'boutique-rollback-test',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer un utilisateur autorisé.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $role = Role::updateOrCreate(
            ['code' => 'STORE_OWNER'],
            ['name' => 'Propriétaire de boutique']
        );

        $permission = Permission::updateOrCreate(
            ['code' => 'payments.reconciliation.resolve'],
            ['name' => 'Résoudre un dossier de réconciliation']
        );

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $user->roles()->syncWithoutDetaching([
            $role->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        $this->assertTrue(
            $user->hasPermission('payments.reconciliation.resolve')
        );

        // 3. Créer une commande non payée.
        $customer = Customer::create([
            'name' => 'Client Rollback',
            'phone' => '690000901',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-ROLLBACK-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Rollback',
            'customer_phone' => '690000901',
            'subtotal' => '15000.00',
            'total' => '15000.00',
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '15000.00',
            'method' => 'MTN_MOMO',
            'status' => 'FAILED',
            'reference' => 'ZM-ROLLBACK-PAY-001',
        ]);

        // 4. Créer un dossier ouvert.
        $case = new MobileMoneyReconciliationCase();

        $case->forceFill([
            'store_id' => $store->id,
            'payment_id' => $payment->id,
            'provider' => 'MTN_MOMO',
            'payment_reference' => $payment->reference,
            'provider_transaction_id' => 'FAKE-ROLLBACK-TX-001',
            'recipient_merchant_account_id' => 'FAKE-MERCHANT-001',
            'amount' => '15000.00',
            'currency' => 'XAF',
            'original_payment_status' => 'FAILED',
            'reported_provider_status' => 'SUCCESS',
            'status' => 'OPEN',
            'reason' => 'Succès tardif signalé par le prestataire.',
        ]);

        $case->save();


        // Simuler une véritable erreur SQL
        // lors de l'enregistrement de RESOLVED.
        DB::unprepared(<<<'SQL'
CREATE TEMP TRIGGER force_resolved_event_failure
BEFORE INSERT ON mobile_money_reconciliation_events
WHEN NEW.event_type = 'RESOLVED'
BEGIN
    SELECT RAISE(
        ABORT,
        'SIMULATED_RESOLVED_EVENT_FAILURE'
    );
END;
SQL);

        try {
            try {
                app(
                    ResolveMobileMoneyReconciliationCase::class
                )->execute(
                    $case->id,
                    'Simulation d une erreur SQL sur RESOLVED.'
                );

                $this->fail(
                    'Une erreur SQL était attendue.'
                );

            } catch (
                \Illuminate\Database\QueryException $exception
            ) {
                $this->assertStringContainsString(
                    'SIMULATED_RESOLVED_EVENT_FAILURE',
                    $exception->getMessage()
                );
            }
        } finally {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS force_resolved_event_failure'
            );
        }

        // Le dossier doit rester ouvert après l'échec.
        $this->assertSame(
            'OPEN',
            $case->fresh()->status
        );

        $this->assertNull($case->fresh()->resolved_at);
        $this->assertNull($case->fresh()->resolved_by);
        $this->assertNull($case->fresh()->resolution_notes);

        // Aucun événement RESOLVED conservé.
        $this->assertSame(
            0,
            DB::table('mobile_money_reconciliation_events')
                ->where('reconciliation_case_id', $case->id)
                ->where('event_type', 'RESOLVED')
                ->count()
        );


        $notes = 'Dossier examiné avec simulation de rollback SQL.';

        // 5. Simuler une erreur avant le COMMIT
        // de la transaction englobante.
        try {
            DB::transaction(function () use (
                $case,
                $notes,
                $store,
                $user
            ) {
                $resolved = app(
                    ResolveMobileMoneyReconciliationCase::class
                )->execute($case->id, $notes);

                // La résolution a bien été effectuée
                // à l'intérieur de la transaction.
                $this->assertSame(
                    'RESOLVED',
                    $resolved->status
                );

                // L'événement est également présent
                // avant l'annulation de la transaction.
                $this->assertDatabaseHas(
                    'mobile_money_reconciliation_events',
                    [
                        'store_id' => $store->id,
                        'reconciliation_case_id' => $case->id,
                        'actor_user_id' => $user->id,
                        'event_type' => 'RESOLVED',
                    ]
                );

                // Déclencher volontairement le rollback.
                throw new \RuntimeException(
                    'SIMULATED_TRANSACTION_FAILURE'
                );
            });

            $this->fail(
                'La transaction aurait dû échouer.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'SIMULATED_TRANSACTION_FAILURE',
                $exception->getMessage()
            );
        }

        // 6. Vérifier le rollback du dossier.
        $this->assertSame(
            'OPEN',
            $case->fresh()->status
        );

        $this->assertNull($case->fresh()->resolved_at);
        $this->assertNull($case->fresh()->resolved_by);
        $this->assertNull($case->fresh()->resolution_notes);

        // 7. Aucun événement RESOLVED conservé.
        $this->assertSame(
            0,
            DB::table('mobile_money_reconciliation_events')
                ->where('reconciliation_case_id', $case->id)
                ->where('event_type', 'RESOLVED')
                ->count()
        );

        // 8. Aucune modification financière.
        $this->assertSame(
            'FAILED',
            $payment->fresh()->status
        );

        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        // 9. Vérifier que la résolution reste possible
        // après l'annulation de la transaction.
        $resolved = app(
            ResolveMobileMoneyReconciliationCase::class
        )->execute($case->id, $notes);

        $this->assertSame(
            'RESOLVED',
            $resolved->status
        );

        // 10. L'événement est créé une seule fois.
        $this->assertSame(
            1,
            DB::table('mobile_money_reconciliation_events')
                ->where('reconciliation_case_id', $case->id)
                ->where('event_type', 'RESOLVED')
                ->count()
        );
    }
}
