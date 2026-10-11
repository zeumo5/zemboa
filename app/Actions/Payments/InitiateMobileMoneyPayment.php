<?php
namespace App\Actions\Payments;



use App\Models\Order;
use App\Models\Payment;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InitiateMobileMoneyPayment
{
    public function execute(
        int $orderId,
        string $method
    ): Payment {
        // Accepter uniquement les moyens Mobile Money.
        if (!in_array($method, [
            'MTN_MOMO',
            'ORANGE_MONEY',
        ], true)) {
            throw ValidationException::withMessages([
                'method' => 'Moyen de paiement Mobile Money invalide.',
            ]);
        }

        // Le contexte de boutique doit déjà être établi
        // par une couche d'accès sécurisée.
        $storeId = app(TenantContext::class)->storeId();

        if ($storeId === null) {
            throw ValidationException::withMessages([
                'store' => 'Aucune boutique active.',
            ]);
        }

        return DB::transaction(function () use (
            $orderId,
            $method,
            $storeId
        ) {
            // Verrouiller la commande avant toute vérification.
            $order = Order::query()
                ->where('store_id', $storeId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'order' => 'Cette commande ne peut plus être payée.',
                ]);
            }

            if (!in_array($order->payment_status, [
                'UNPAID',
                'PARTIALLY_PAID',
            ], true)) {
                throw ValidationException::withMessages([
                    'payment_status' =>
                        'Cette commande est déjà entièrement payée.',
                ]);
            }

            // Récupérer les paiements existants.
            $payments = Payment::query()
                ->where('store_id', $storeId)
                ->where('order_id', $order->id)
                ->whereIn('status', ['PENDING', 'CONFIRMED'])
                ->lockForUpdate()
                ->get(['status', 'amount']);

            // Éviter deux demandes de paiement simultanées
            // sur la même commande dans cette première version.
            if ($payments->contains('status', 'PENDING')) {
                throw ValidationException::withMessages([
                    'payment' =>
                        'Un paiement est déjà en attente pour cette commande.',
                ]);
            }

            // Convertir les montants en centimes pour
            // éviter les calculs avec des nombres flottants.
            $toCents = static function (string $amount): int {
                if (!preg_match(
                    '/^\d{1,10}(\.\d{1,2})?$/',
                    $amount
                )) {
                    throw ValidationException::withMessages([
                        'amount' => 'Montant de commande invalide.',
                    ]);
                }

                [$whole, $decimal] = array_pad(
                    explode('.', $amount, 2),
                    2,
                    '0'
                );

                return ((int) $whole * 100)
                    + (int) str_pad($decimal, 2, '0');
            };

            $total = $toCents($order->total);

            $alreadyPaid = $payments
                ->where('status', 'CONFIRMED')
                ->sum(
                    fn ($payment) => $toCents($payment->amount)
                );

            $remaining = $total - $alreadyPaid;

            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Aucun solde à payer.',
                ]);
            }

            $amount = intdiv($remaining, 100)
                . '.'
                . str_pad(
                    (string) ($remaining % 100),
                    2,
                    '0',
                    STR_PAD_LEFT
                );

            // Création interne seulement.
            // Aucune requête au prestataire n'est envoyée ici.
            return Payment::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'method' => $method,
                'status' => 'PENDING',
                'reference' => (string) Str::uuid(),
            ]);
        }, 3);
    }
}
