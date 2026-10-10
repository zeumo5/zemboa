<?php

namespace App\Actions\Orders;


use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

class ListOverdueBalances
{
    public function execute(): Collection
    {
        return Order::query()
            // Seulement les commandes encore en attente.
            ->where('status', 'PENDING')

            // Seulement les commandes avec acompte.
            ->where('payment_status', 'PARTIALLY_PAID')

            // La date limite doit exister.
            ->whereNotNull('balance_due_at')

            // Le délai de paiement du solde est dépassé.
            ->where('balance_due_at', '<', now())

            // Afficher les plus anciennes échéances d'abord.
            ->orderBy('balance_due_at')

            ->get();
    }
}
