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

class ResolveMobileMoneyReconciliationCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_owner_can_resolve_case_without_confirming_payment(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Resolution Test',
            'slug' => 'boutique-resolution-test',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer un propriétaire.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        // 3. Donner la permission de résolution.
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

        // 4. Créer une commande non payée.
        $customer = Customer::create([
            'name' => 'Client Resolution',
            'phone' => '690000801',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-RESOLVE-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Resolution',
            'customer_phone' => '690000801',
            'subtotal' => '12000.00',
            'total' => '12000.00',
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '12000.00',
            'method' => 'MTN_MOMO',
            'status' => 'FAILED',
            'reference' => 'ZM-RESOLVE-PAY-001',
        ]);

        // 5. Préparer un dossier fictif déjà ouvert.
        // Aucun véritable paiement Mobile Money.
        $case = new MobileMoneyReconciliationCase();

        $case->forceFill([
            'store_id' => $store->id,
            'payment_id' => $payment->id,
            'provider' => 'MTN_MOMO',
            'payment_reference' => $payment->reference,
            'provider_transaction_id' => 'FAKE-RESOLVE-TX-001',
            'recipient_merchant_account_id' => 'FAKE-MERCHANT-001',
            'amount' => '12000.00',
            'currency' => 'XAF',
            'original_payment_status' => 'FAILED',
            'reported_provider_status' => 'SUCCESS',
            'status' => 'OPEN',
            'reason' => 'Succès tardif signalé par le prestataire.',
        ]);

        $case->save();


        // Vérifier l'isolation entre deux boutiques.
        $storeB = Store::create([
            'name' => 'Boutique Resolution Beta',
            'slug' => 'boutique-resolution-beta',
            'status' => 'ACTIVE',
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        // Le rôle possède déjà la permission resolve.
        $userB->roles()->attach($role->id);

        $this->assertTrue(
            $userB->hasPermission('payments.reconciliation.resolve')
        );

        // Passer dans la boutique B.
        $this->actingAs($userB);
        app(TenantContext::class)->setFromUser($userB);

        // Même avec la permission, le dossier A
        // doit rester inaccessible.
        try {
            app(ResolveMobileMoneyReconciliationCase::class)
                ->execute(
                    $case->id,
                    'Tentative de résolution depuis une autre boutique.'
                );

            $this->fail(
                'Une boutique ne doit pas résoudre '
                . 'le dossier d’une autre boutique.'
            );

        } catch (
            \Illuminate\Database\Eloquent\ModelNotFoundException $exception
        ) {
            $this->assertSame(
                MobileMoneyReconciliationCase::class,
                $exception->getModel()
            );
        }


        // Sécurité : une tentative depuis une autre
        // boutique ne doit créer aucun événement RESOLVED.
        $this->assertSame(
            0,
            DB::table('mobile_money_reconciliation_events')
                ->where('reconciliation_case_id', $case->id)
                ->where('event_type', 'RESOLVED')
                ->count()
        );


        // Revenir dans la boutique A.
        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // Le dossier doit toujours être ouvert.
        $this->assertSame('OPEN', $case->fresh()->status);

        // Aucune modification financière.
        $this->assertSame('FAILED', $payment->fresh()->status);
        $this->assertSame('UNPAID', $order->fresh()->payment_status);


        // 6. Résoudre administrativement le dossier.
        $notes = 'Dossier examiné et clôturé après vérification manuelle.';

        $resolved = app(
            ResolveMobileMoneyReconciliationCase::class
        )->execute($case->id, $notes);


        // 7. Vérifier la décision enregistrée.
        $this->assertSame('RESOLVED', $resolved->status);

        $this->assertSame(
            $notes,
            $resolved->resolution_notes
        );

        $this->assertSame(
            $user->id,
            $resolved->resolved_by
        );

        $this->assertNotNull($resolved->resolved_at);

        // 8. Le paiement doit rester échoué.
        $this->assertSame(
            'FAILED',
            $payment->fresh()->status
        );

        $this->assertNull(
            $payment->fresh()->confirmed_at
        );

        // 9. La commande reste non payée.
        $this->assertSame(
            'UNPAID',
            $order->fresh()->payment_status
        );

        // 10. Aucun mouvement de vente.
        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('store_id', $store->id)
                ->where('type', 'SALE')
                ->count()
        );


        // 11. Empêcher une deuxième résolution du dossier.
        try {
            app(ResolveMobileMoneyReconciliationCase::class)
                ->execute(
                    $case->id,
                    'Tentative de modification de la décision précédente.'
                );

            $this->fail(
                'Un dossier déjà résolu ne doit pas être résolu deux fois.'
            );

        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey(
                'reconciliation',
                $exception->errors()
            );
        }

        // 12. Vérifier que la décision initiale est conservée.
        $this->assertSame(
            'RESOLVED',
            $case->fresh()->status
        );

        $this->assertSame(
            $notes,
            $case->fresh()->resolution_notes
        );

        $this->assertSame(
            $user->id,
            $case->fresh()->resolved_by
        );

        // Le paiement reste inchangé.
        $this->assertSame(
            'FAILED',
            $payment->fresh()->status
        );


        // 13. Vérifier que la résolution est historisée.
        $this->assertDatabaseHas(
            'mobile_money_reconciliation_events',
            [
                'store_id' => $store->id,
                'reconciliation_case_id' => $case->id,
                'actor_user_id' => $user->id,
                'event_type' => 'RESOLVED',
            ]
        );

        // 14. Vérifier l'absence de doublon,
        // même après une deuxième tentative.
        $this->assertSame(
            1,
            DB::table('mobile_money_reconciliation_events')
                ->where('reconciliation_case_id', $case->id)
                ->where('event_type', 'RESOLVED')
                ->count()
        );

        // 15. Récupérer l'événement enregistré.
        $event = DB::table('mobile_money_reconciliation_events')
            ->where('reconciliation_case_id', $case->id)
            ->where('event_type', 'RESOLVED')
            ->firstOrFail();

        // 16. Vérifier la date de l'événement.
        $this->assertNotNull($event->created_at);

        // 17. Vérifier les notes administratives.
        $details = json_decode(
            $event->details,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            $notes,
            $details['resolution_notes']
        );


    }


public function test_user_without_permission_cannot_resolve_case(): void
{
    // 1. Créer une boutique.
    $store = Store::create([
        'name' => 'Boutique Resolution Sans Permission',
        'slug' => 'resolution-sans-permission',
        'status' => 'ACTIVE',
    ]);

    // 2. Créer un utilisateur sans permission.
    $user = User::factory()->create([
        'store_id' => $store->id,
    ]);

    $this->assertFalse(
        $user->hasPermission('payments.reconciliation.resolve')
    );

    // 3. Authentifier l'utilisateur.
    $this->actingAs($user);
    app(TenantContext::class)->setFromUser($user);

    // 4. Tenter de résoudre un dossier.
    // Le contrôle RBAC doit refuser l'accès
    // avant même de rechercher le dossier.
    try {
        app(ResolveMobileMoneyReconciliationCase::class)
            ->execute(
                999999,
                'Tentative de résolution sans autorisation.'
            );

        $this->fail(
            'Un utilisateur sans permission doit être refusé.'
        );

    } catch (
        \Symfony\Component\HttpKernel\Exception\HttpException $exception
    ) {
        $this->assertSame(
            403,
            $exception->getStatusCode()
        );
    }

    // 5. Aucune donnée financière créée.
    $this->assertDatabaseCount(
        'mobile_money_reconciliation_cases',
        0
    );

    $this->assertDatabaseCount(
        'mobile_money_transaction_claims',
        0
    );
}

}
