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

            'payments.view',
            'payments.confirm',
        ])->pluck('id');

        $storeOwner->permissions()->sync($storeOwnerPermissions);

        $deliveryAgent = Role::where('code', 'DELIVERY_AGENT')->firstOrFail();

        $deliveryAgentPermissions = Permission::whereIn('code', [
            'deliveries.view',
            'deliveries.update',
        ])->pluck('id');

        $deliveryAgent->permissions()->sync($deliveryAgentPermissions);
    }
}
