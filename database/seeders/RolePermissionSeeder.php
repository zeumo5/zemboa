<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $storeOwner = Role::where('code', 'STORE_OWNER')->firstOrFail();


        $storeOwnerPermissions = Permission::whereIn('code', [
            'store.view',
            'store.update',

            // Paramètres de la boutique
            'settings.view',
            'settings.update',


            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',

            'products.view',
            'products.create',
            'products.update',
            'products.delete',

            'inventory.view',
            'inventory.manage',

            'orders.view',
            'orders.update',

            'customers.view',
            'customers.create',
            'customers.update',

            'deliveries.view',
            'deliveries.update',

            // Retours après-vente
            'returns.view',
            'returns.manage',

            // Remboursements
            'refunds.view',
            'refunds.manage',


            // Paiements
            'payments.view',
            'payments.confirm',

            // Réconciliation Mobile Money
            'payments.reconciliation.view',
            'payments.reconciliation.create',
            'payments.reconciliation.resolve',

            'pos.registers.view',
            'pos.registers.manage',

            'pos.sessions.open',
            'pos.sessions.close',
            'pos.sales.create',
            'pos.cash_movements.manage',

            'store.document_settings.view',
            'store.document_settings.update',

        ])->pluck('id');

        $storeOwner->permissions()->sync($storeOwnerPermissions);

        $deliveryAgent = Role::where('code', 'DELIVERY_AGENT')->firstOrFail();

        $deliveryAgentPermissions = Permission::whereIn('code', [
            'deliveries.view',
            'deliveries.update',
        ])->pluck('id');

        $deliveryAgent->permissions()->sync($deliveryAgentPermissions);

        // Permissions minimales du personnel de caisse.
        $cashier = Role::where('code', 'CASHIER')->firstOrFail();

        $cashierPermissions = Permission::whereIn('code', [
            'orders.view',
            'payments.view',

            'payments.confirm',
            'pos.registers.view',

            'pos.sessions.open',
            'pos.sessions.close',
            'pos.sales.create',
        ])->pluck('id');

        $cashier->permissions()->sync($cashierPermissions);

        // Gestionnaire de boutique ZEMBOA.
        // Gestion opérationnelle sans accès financier sensible.
        $storeManager = Role::where('code', 'STORE_MANAGER')
            ->firstOrFail();

        $storeManagerPermissions = Permission::whereIn('code', [
            // Catalogue
            'categories.view',
            'categories.create',
            'categories.update',

            'products.view',
            'products.create',
            'products.update',

            // Stock
            'inventory.view',
            'inventory.manage',

            // Commandes
            'orders.view',
            'orders.update',

            // Clients
            'customers.view',

            // Livraisons
            'deliveries.view',
            'deliveries.update',

            'pos.registers.view',
            'pos.registers.manage',

            'pos.sessions.open',
            'pos.sessions.close',
            'pos.sales.create',
            'pos.cash_movements.manage',

            // Paramètres des factures et reçus
            'store.document_settings.view',
            'store.document_settings.update',

        ])->pluck('id');

        $storeManager->permissions()->sync($storeManagerPermissions);
    }
}
