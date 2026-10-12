<?php
namespace Tests\Feature;


use App\Actions\Payments\FinalizeOrderPayment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MobileMoneyTotalCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_money_respects_existing_deposit(): void
    {
        // 1. Préparer la boutique.
        $store = Store::create([
            'name' => 'Boutique Acompte',
            'slug' => 'boutique-acompte-momo',
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create([
            'store_id' => $store->id,
        ]);

        $this->actingAs($user);
        app(TenantContext::class)->setFromUser($user);

        // 2. Créer le client et la commande.
        $customer = Customer::create([
            'name' => 'Client Acompte',
            'phone' => '+237690000070',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_number' => 'ZM-ACOMPTE-001',
            'fulfillment_type' => 'PICKUP',
            'customer_name' => 'Client Acompte',
            'customer_phone' => '+237690000070',
            'subtotal' => '25000.00',
            'total' => '25000.00',
            'payment_status' => 'PARTIALLY_PAID',
        ]);

        // 3. Simuler un acompte CASH déjà confirmé.
        Payment::create([
            'order_id' => $order->id,
            'amount' => '10000.00',
            'method' => 'CASH',
            'status' => 'CONFIRMED',
            'reference' => 'ZM-CASH-ACOMPTE-001',
        ]);

        // 4. Créer le paiement Mobile Money restant.
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => '15000.00',
            'method' => 'MTN_MOMO',
            'status' => 'PENDING',
            'reference' => 'ZM-MOMO-ACOMPTE-001',
        ]);

        // 5. Verrouiller et calculer.
        $totalPaid = DB::transaction(function () use (
            $order,
            $payment
        ) {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            return app(FinalizeOrderPayment::class)
                ->calculateLockedMobileMoneyTotal(
                    order: $lockedOrder,
                    payment: $lockedPayment
                );
        });

        // 25 000 FCFA = 2 500 000 centimes.
        $this->assertSame(2500000, $totalPaid);

        // 6. Simuler un paiement excessif.
        $payment->amount = '16000.00';
        $payment->save();

        try {
            DB::transaction(function () use ($order, $payment) {
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedPayment = Payment::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                app(FinalizeOrderPayment::class)
                    ->calculateLockedMobileMoneyTotal(
                        order: $lockedOrder,
                        payment: $lockedPayment
                    );
            });

            $this->fail(
                'Un paiement excessif aurait dû être refusé.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'amount',
                $exception->errors()
            );
        }

        // 7. Aucun paiement Mobile Money confirmé.
        $this->assertSame(
            'PENDING',
            $payment->fresh()->status
        );

        $this->assertSame(
            'PARTIALLY_PAID',
            $order->fresh()->payment_status
        );
    }
}
