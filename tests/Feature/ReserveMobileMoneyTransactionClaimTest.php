<?php
namespace Tests\Feature;


use App\Actions\Payments\ReserveMobileMoneyTransactionClaim;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReserveMobileMoneyTransactionClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_external_transaction_is_rejected_across_stores(): void
    {
        // Créer un paiement dans une boutique.
        $createPayment = function (string $suffix): Payment {
            $store = Store::create([
                'name' => 'Boutique Claim ' . $suffix,
                'slug' => 'boutique-claim-' . strtolower($suffix),
                'status' => 'ACTIVE',
            ]);

            $user = User::factory()->create([
                'store_id' => $store->id,
            ]);

            $this->actingAs($user);
            app(TenantContext::class)->setFromUser($user);

            $customer = Customer::create([
                'name' => 'Client ' . $suffix,
                'phone' => '69000010' . ($suffix === 'A' ? '1' : '2'),
            ]);

            $order = Order::create([
                'customer_id' => $customer->id,
                'order_number' => 'ZM-CLAIM-' . $suffix,
                'fulfillment_type' => 'PICKUP',
                'customer_name' => 'Client ' . $suffix,
                'customer_phone' => $customer->phone,
                'subtotal' => '10000.00',
                'total' => '10000.00',
            ]);

            return Payment::create([
                'order_id' => $order->id,
                'amount' => '10000.00',
                'method' => 'MTN_MOMO',
                'status' => 'PENDING',
                'reference' => 'ZM-CLAIM-PAY-' . $suffix,
            ]);
        };

        // 1. Première boutique.
        $paymentA = $createPayment('A');

        $action = app(
            ReserveMobileMoneyTransactionClaim::class
        );

        // 2. Réserver une transaction externe.
        DB::transaction(function () use ($action, $paymentA) {
            $action->execute(
                payment: $paymentA,
                provider: 'MTN_MOMO',
                providerTransactionId: 'MTN-TX-CLAIM-001',
                claimType: 'CONFIRMATION'
            );
        });

        $this->assertSame(
            1,
            DB::table('mobile_money_transaction_claims')->count()
        );

        // 3. Répéter exactement la même opération.
        DB::transaction(function () use ($action, $paymentA) {
            $action->execute(
                payment: $paymentA,
                provider: 'MTN_MOMO',
                providerTransactionId: 'MTN-TX-CLAIM-001',
                claimType: 'CONFIRMATION'
            );
        });

        // Aucun doublon.
        $this->assertSame(
            1,
            DB::table('mobile_money_transaction_claims')->count()
        );

        // 4. Deuxième boutique, autre paiement.
        $paymentB = $createPayment('B');

        // 5. Essayer de réutiliser la transaction de A.
        try {
            DB::transaction(function () use ($action, $paymentB) {
                $action->execute(
                    payment: $paymentB,
                    provider: 'MTN_MOMO',
                    providerTransactionId: 'MTN-TX-CLAIM-001',
                    claimType: 'CONFIRMATION'
                );
            });

            $this->fail(
                'La même transaction externe ne doit pas être '
                . 'attribuée à deux paiements.'
            );

        } catch (QueryException $exception) {
            // La contrainte UNIQUE refuse le doublon.
            $this->assertNotEmpty($exception->getMessage());
        }

        // 6. Le registre conserve une seule réservation.
        $this->assertSame(
            1,
            DB::table('mobile_money_transaction_claims')->count()
        );

        // 7. Aucun paiement n'a été confirmé.
        $this->assertSame(
            'PENDING',
            DB::table('payments')
                ->where('id', $paymentA->id)
                ->value('status')
        );

        $this->assertSame(
            'PENDING',
            $paymentB->fresh()->status
        );
    }
}
