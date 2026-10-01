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

            // Livraisons
            ['name' => 'Voir les livraisons', 'code' => 'deliveries.view'],
            ['name' => 'Modifier les livraisons', 'code' => 'deliveries.update'],

            // Paiements
            ['name' => 'Voir les paiements', 'code' => 'payments.view'],
            ['name' => 'Confirmer un paiement', 'code' => 'payments.confirm'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['code' => $permission['code']],
                ['name' => $permission['name']]
            );
        }
    }
}