<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\MobileMoneyReconciliationCase;
use App\Support\TenantContext;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileMoneyReconciliationHttpTest extends TestCase
{

    use RefreshDatabase;


    public function test_guest_cannot_view_reconciliation_cases(): void
    {
        // 1. Un visiteur tente de consulter la liste.
        $this->getJson(
            route('payments.reconciliations.index')
        )->assertUnauthorized();

        // 2. Le même visiteur tente de consulter
        // directement un dossier par son identifiant.
        $this->getJson(
            route('payments.reconciliations.show', [
                'caseId' => 999999,
            ])
        )->assertUnauthorized();

        // Un visiteur ne peut pas résoudre un dossier.
        $this->patchJson(
            route('payments.reconciliations.resolve', [
                'caseId' => 999999,
            ]),
            [
                'resolution_notes' =>
                'Tentative de résolution sans authentification.',
            ]
        )->assertUnauthorized();
    }




    public function test_user_without_permission_cannot_view_reconciliation_cases(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Test HTTP RBAC',
            'slug' => 'boutique-test-http-rbac',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer un utilisateur associé à cette boutique,
        // mais sans rôle ni permission.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        // 3. Vérifier qu'il n'a pas la permission.
        $this->assertFalse(
            $user->hasPermission('payments.reconciliation.view')
        );

        // 4. Authentifier l'utilisateur.
        $this->actingAs($user);

        // 5. Tenter de consulter les dossiers.
        $response = $this->getJson(
            route('payments.reconciliations.index')
        );


        // Vérifier également la consultation détaillée.
        // Même avec un identifiant de dossier connu,
        // l'utilisateur sans permission doit être refusé.
        $this->getJson(
            route('payments.reconciliations.show', [
                'caseId' => 999999,
            ])
        )->assertForbidden();


        // 6. L'accès doit être refusé.
        $response->assertForbidden();

        // 7. Aucun dossier ne doit être créé.
        $this->assertDatabaseCount(
            'mobile_money_reconciliation_cases',
            0
        );


        // Un utilisateur connecté sans permission
        // ne peut pas résoudre un dossier.
        $this->patchJson(
            route('payments.reconciliations.resolve', [
                'caseId' => 999999,
            ]),
            [
                'resolution_notes' =>
                'Tentative de résolution sans permission.',
            ]
        )->assertForbidden();
    }


    public function test_store_owner_can_view_reconciliation_cases(): void
    {
        // 1. Créer une boutique.
        $store = Store::create([
            'name' => 'Boutique Consultation HTTP',
            'slug' => 'boutique-consultation-http',
            'status' => 'ACTIVE',
        ]);

        // 2. Créer son propriétaire.
        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        // 3. Préparer le rôle propriétaire.
        $role = Role::updateOrCreate(
            ['code' => 'STORE_OWNER'],
            ['name' => 'Propriétaire de boutique']
        );

        // 4. Préparer la permission de consultation.
        $permission = Permission::updateOrCreate(
            ['code' => 'payments.reconciliation.view'],
            ['name' => 'Consulter les dossiers de réconciliation']
        );

        // 5. Associer la permission au rôle.
        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        // 6. Attribuer le rôle à l'utilisateur.
        $user->roles()->syncWithoutDetaching([
            $role->id,
        ]);

        $this->assertTrue(
            $user->hasPermission('payments.reconciliation.view')
        );

        // 7. Authentifier le propriétaire.
        $this->actingAs($user);

        // 8. Consulter les dossiers par HTTP.
        $response = $this->getJson(
            route('payments.reconciliations.index')
        );

        // 9. La consultation doit être autorisée.
        $response->assertOk();

        // Aucun dossier n'a été créé dans ce scénario.
        $response->assertJsonPath('data', []);
    }


    public function test_store_owner_only_sees_own_reconciliation_cases(): void
    {
        // 1. Préparer le rôle et la permission.
        $role = Role::updateOrCreate(
            ['code' => 'STORE_OWNER'],
            ['name' => 'Propriétaire de boutique']
        );

        $permission = Permission::updateOrCreate(
            ['code' => 'payments.reconciliation.view'],
            ['name' => 'Consulter les dossiers de réconciliation']
        );

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);


        $resolvePermission = Permission::updateOrCreate(
            ['code' => 'payments.reconciliation.resolve'],
            ['name' => 'Résoudre un dossier de réconciliation']
        );

        $role->permissions()->syncWithoutDetaching([
            $resolvePermission->id,
        ]);


        // 2. Créer deux boutiques indépendantes.
        $storeA = Store::create([
            'name' => 'Boutique HTTP Alpha',
            'slug' => 'recon-http-alpha',
            'status' => 'ACTIVE',
        ]);

        $storeB = Store::create([
            'name' => 'Boutique HTTP Beta',
            'slug' => 'recon-http-beta',
            'status' => 'ACTIVE',
        ]);

        $userA = User::factory()->create([
            'store_id' => $storeA->id,
        ]);

        $userB = User::factory()->create([
            'store_id' => $storeB->id,
        ]);

        $userA->roles()->attach($role->id);
        $userB->roles()->attach($role->id);

        // 3. Préparer un dossier pour chaque boutique.
        $createCase = function (
            User $user,
            string $suffix
        ): MobileMoneyReconciliationCase {

            $this->actingAs($user);

            app(TenantContext::class)->setFromUser($user);

            $customer = Customer::create([
                'name' => 'Client ' . $suffix,
                'phone' => '690000701',
            ]);

            $order = Order::create([
                'customer_id' => $customer->id,
                'order_number' => 'ZM-HTTP-RECON-' . $suffix,
                'fulfillment_type' => 'PICKUP',
                'customer_name' => 'Client ' . $suffix,
                'customer_phone' => '690000701',
                'subtotal' => '5000.00',
                'total' => '5000.00',
            ]);

            $payment = Payment::create([
                'order_id' => $order->id,
                'amount' => '5000.00',
                'method' => 'MTN_MOMO',
                'status' => 'FAILED',
                'reference' => 'ZM-HTTP-PAY-' . $suffix,
            ]);

            $case = new MobileMoneyReconciliationCase();

            // Données fictives créées uniquement
            // pour tester l'isolation HTTP.
            $case->forceFill([
                'store_id' => $user->store_id,
                'payment_id' => $payment->id,
                'provider' => 'MTN_MOMO',
                'payment_reference' => $payment->reference,
                'provider_transaction_id' => 'FAKE-HTTP-TX-' . $suffix,
                'recipient_merchant_account_id' =>
                'FAKE-MERCHANT-' . $suffix,
                'amount' => '5000.00',
                'currency' => 'XAF',
                'original_payment_status' => 'FAILED',
                'reported_provider_status' => 'SUCCESS',
                'status' => 'OPEN',
                'reason' => 'Test isolation HTTP',
            ]);

            $case->save();

            return $case;
        };

        $caseA = $createCase($userA, 'A');
        $caseB = $createCase($userB, 'B');

        // 4. Le propriétaire A consulte ses dossiers.
        $this->actingAs($userA);
        app(TenantContext::class)->setFromUser($userA);

        $responseA = $this->getJson(
            route('payments.reconciliations.index')
        );

        $responseA->assertOk();
        $responseA->assertJsonCount(1, 'data');

        $responseA->assertJsonPath(
            'data.0.id',
            $caseA->id
        );

        $responseA->assertJsonMissing([
            'id' => $caseB->id,
        ]);

        // 5. Le propriétaire B consulte ses dossiers.
        $this->actingAs($userB);
        app(TenantContext::class)->setFromUser($userB);

        $responseB = $this->getJson(
            route('payments.reconciliations.index')
        );

        $responseB->assertOk();
        $responseB->assertJsonCount(1, 'data');

        $responseB->assertJsonPath(
            'data.0.id',
            $caseB->id
        );

        $responseB->assertJsonMissing([
            'id' => $caseA->id,
        ]);


        // 9. Revenir dans la boutique A.
        $this->actingAs($userA);
        app(TenantContext::class)->setFromUser($userA);

        // La boutique A ne peut pas résoudre le dossier B.
        $this->patchJson(
            route('payments.reconciliations.resolve', [
                'caseId' => $caseB->id,
            ]),
            [
                'resolution_notes' =>
                'Tentative de résolution du dossier de la boutique B.',
            ]
        )->assertNotFound();

        // Les deux dossiers restent ouverts.
        $this->assertSame('OPEN', $caseA->fresh()->status);

        $this->assertSame(
            'OPEN',
            \Illuminate\Support\Facades\DB::table(
                'mobile_money_reconciliation_cases'
            )->where('id', $caseB->id)->value('status')
        );

        // 10. La boutique A résout son propre dossier.
        $notes = 'Dossier examiné et clôturé après vérification administrative.';

        $this->patchJson(
            route('payments.reconciliations.resolve', [
                'caseId' => $caseA->id,
            ]),
            [
                'resolution_notes' => $notes,
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.resolved_by', $userA->id)
            ->assertJsonPath('data.resolution_notes', $notes);

        // 11. Vérifier que seule la résolution A a changé.
        $this->assertSame('RESOLVED', $caseA->fresh()->status);
        $this->assertNotNull($caseA->fresh()->resolved_at);

        $this->assertSame(
            'OPEN',
            \Illuminate\Support\Facades\DB::table(
                'mobile_money_reconciliation_cases'
            )->where('id', $caseB->id)->value('status')
        );

        // Aucun paiement confirmé automatiquement.
        $this->assertSame(
            'FAILED',
            Payment::query()
                ->whereKey($caseA->payment_id)
                ->firstOrFail()
                ->status
        );


        // 6. Aucun dossier n'a été supprimé.
        $this->assertDatabaseCount(
            'mobile_money_reconciliation_cases',
            2
        );


    // 12. Refuser une deuxième résolution du dossier A.
    $this->actingAs($userA);
    app(TenantContext::class)->setFromUser($userA);

    $this->patchJson(
        route('payments.reconciliations.resolve', [
            'caseId' => $caseA->id,
        ]),
        [
            'resolution_notes' =>
                'Tentative de modification de la décision précédente.',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reconciliation']);

    // La première décision doit rester inchangée.
    $this->assertSame('RESOLVED', $caseA->fresh()->status);
    $this->assertSame($notes, $caseA->fresh()->resolution_notes);
    $this->assertSame($userA->id, $caseA->fresh()->resolved_by);

    // 13. Refuser les notes trop courtes sur le dossier B.
    $this->actingAs($userB);
    app(TenantContext::class)->setFromUser($userB);

    $this->patchJson(
        route('payments.reconciliations.resolve', [
            'caseId' => $caseB->id,
        ]),
        [
            'resolution_notes' => 'OK',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['resolution_notes']);

    // Le dossier B reste ouvert.
    $this->assertSame('OPEN', $caseB->fresh()->status);
    $this->assertNull($caseB->fresh()->resolved_at);
    $this->assertNull($caseB->fresh()->resolved_by);


        // 7. Le propriétaire A consulte son propre dossier.
        $this->actingAs($userA);
        app(TenantContext::class)->setFromUser($userA);

        $this->getJson(
            route('payments.reconciliations.show', [
                'caseId' => $caseA->id,
            ])
        )
            ->assertOk()
            ->assertJsonPath('data.id', $caseA->id);

        // La boutique A ne doit pas accéder au dossier B.
        $this->getJson(
            route('payments.reconciliations.show', [
                'caseId' => $caseB->id,
            ])
        )->assertNotFound();

        // 8. Le propriétaire B consulte son propre dossier.
        $this->actingAs($userB);
        app(TenantContext::class)->setFromUser($userB);

        $this->getJson(
            route('payments.reconciliations.show', [
                'caseId' => $caseB->id,
            ])
        )
            ->assertOk()
            ->assertJsonPath('data.id', $caseB->id);

        // La boutique B ne doit pas accéder au dossier A.
        $this->getJson(
            route('payments.reconciliations.show', [
                'caseId' => $caseA->id,
            ])
        )->assertNotFound();
    }
}
