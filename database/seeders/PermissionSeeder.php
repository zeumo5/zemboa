<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Boutique
            ['name' => 'Voir la boutique', 'code' => 'store.view'],
            ['name' => 'Modifier la boutique', 'code' => 'store.update'],
            ['name' => 'Voir les paramètres documentaires', 'code' => 'store.document_settings.view'],
            ['name' => 'Modifier les paramètres documentaires', 'code' => 'store.document_settings.update'],

            // Catégories
            ['name' => 'Voir les catégories', 'code' => 'categories.view'],
            ['name' => 'Créer une catégorie', 'code' => 'categories.create'],
            ['name' => 'Modifier une catégorie', 'code' => 'categories.update'],
            ['name' => 'Supprimer une catégorie', 'code' => 'categories.delete'],

            // Produits
            ['name' => 'Voir les produits', 'code' => 'products.view'],
            ['name' => 'Créer un produit', 'code' => 'products.create'],
            ['name' => 'Modifier un produit', 'code' => 'products.update'],
            ['name' => 'Supprimer un produit', 'code' => 'products.delete'],

            // Stock
            ['name' => 'Voir le stock', 'code' => 'inventory.view'],
            ['name' => 'Gérer le stock', 'code' => 'inventory.manage'],

            // Commandes
            ['name' => 'Voir les commandes', 'code' => 'orders.view'],
            ['name' => 'Modifier les commandes', 'code' => 'orders.update'],


            // Clients
            ['name' => 'Voir les clients', 'code' => 'customers.view'],
            ['name' => 'Créer un client', 'code' => 'customers.create'],
            ['name' => 'Modifier un client', 'code' => 'customers.update'],

            // Livraisons
            ['name' => 'Voir les livraisons', 'code' => 'deliveries.view'],
            ['name' => 'Modifier les livraisons', 'code' => 'deliveries.update'],

            // Retours après-vente
            [
                'name' => 'Consulter les demandes de retour',
                'code' => 'returns.view',
            ],
            [
                'name' => 'Gérer les demandes de retour',
                'code' => 'returns.manage',
            ],

            // Remboursements
            [
                'name' => 'Consulter les remboursements',
                'code' => 'refunds.view',
            ],
            [
                'name' => 'Gérer les remboursements',
                'code' => 'refunds.manage',
            ],

            // Paiements

            // Paiements
            ['name' => 'Voir les paiements', 'code' => 'payments.view'],
            ['name' => 'Confirmer un paiement', 'code' => 'payments.confirm'],

            // Réconciliation Mobile Money
            [
                'name' => 'Consulter les dossiers de réconciliation',
                'code' => 'payments.reconciliation.view',
            ],
            [
                'name' => 'Ouvrir un dossier de réconciliation',
                'code' => 'payments.reconciliation.create',
            ],
            [
                'name' => 'Résoudre un dossier de réconciliation',
                'code' => 'payments.reconciliation.resolve',
            ],


            // Paramètres de la boutique
            [
                'name' => 'Consulter les paramètres de la boutique',
                'code' => 'settings.view',
            ],
            [
                'name' => 'Modifier les paramètres de la boutique',
                'code' => 'settings.update',
            ],

            // Gestion des caisses POS
            [
                'name' => 'Consulter les caisses POS',
                'code' => 'pos.registers.view',
            ],
            [
                'name' => 'Gérer les caisses POS',
                'code' => 'pos.registers.manage',
            ],


            // Permissions des caisses POS
            [
                'name' => 'Consulter les caisses POS',
                'code' => 'pos.registers.view',
            ],
            [
                'name' => 'Gérer les caisses POS',
                'code' => 'pos.registers.manage',
            ],

            // Sessions de caisse POS
            [
                'name' => 'Ouvrir une session de caisse',
                'code' => 'pos.sessions.open',
            ],
            [
                'name' => 'Clôturer une session de caisse',
                'code' => 'pos.sessions.close',
            ],

            // Ventes en caisse POS
            [
                'name' => 'Créer une vente en caisse',
                'code' => 'pos.sales.create',
            ],

            [
                'name' => 'Gérer les mouvements manuels de caisse',
                'code' => 'pos.cash_movements.manage',
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['code' => $permission['code']],
                ['name' => $permission['name']]
            );
        }
    }
}
