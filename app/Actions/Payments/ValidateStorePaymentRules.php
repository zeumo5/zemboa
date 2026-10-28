<?php

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\StoreSetting;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ValidateStorePaymentRules
{
    public function __construct(
        private readonly FinalizeOrderPayment $finalizePayment
    ) {}

    public function execute(
        Order $order,
        string $alreadyPaid,
        string $newPayment
    ): int {
        // Vérifier la boutique courante.
        $storeId = app(TenantContext::class)->storeId();

        if ((int) $order->store_id !== (int) $storeId) {
            throw ValidationException::withMessages([
                'order' => 'Commande hors de votre boutique.',
            ]);
        }

        // Réutiliser les contrôles monétaires existants.
        $newTotalPaid = $this->finalizePayment
            ->validatePaymentTotal(
                (string) $order->total,
                $alreadyPaid,
                $newPayment
            );

        $total = $this->finalizePayment
            ->amountToCents((string) $order->total);

        $paid = $this->finalizePayment
            ->amountToCents($alreadyPaid);

        // Le règlement intégral est toujours autorisé.
        if ($newTotalPaid === $total) {
            return $newTotalPaid;
        }

        // Paramètres de la boutique courante.
        $settings = StoreSetting::query()->first();

        // Compatibilité avec les boutiques non configurées.
        if ($settings === null || ! $settings->hasCommercialConfiguration()) {
            throw ValidationException::withMessages(['amount' => __('payments.partial_configuration_required')]);
        }

        // Vérifier l'autorisation des paiements partiels.
        if (! $settings->allow_partial_payments) {
            throw ValidationException::withMessages([
                'amount' => 'Cette boutique refuse les paiements partiels.',
            ]);
        }

        // Minimum applicable au premier acompte uniquement.
        if ($paid === 0) {
            $percentage = (int)
                $settings->minimum_deposit_percent;

            if ($percentage < 0 || $percentage > 100) {
                throw new LogicException(
                    'Configuration du pourcentage invalide.'
                );
            }

            // Arrondi supérieur sans nombres flottants.
            $minimum = intdiv(
                ($total * $percentage) + 99,
                100
            );

            if ($newTotalPaid < $minimum) {
                throw ValidationException::withMessages([
                    'amount' => 'Le premier acompte est inférieur au minimum exigé par la boutique.',
                ]);
            }
        }

        return $newTotalPaid;
    }
}
