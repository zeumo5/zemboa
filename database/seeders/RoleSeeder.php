<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::updateOrCreate(
            ['code' => 'SUPER_ADMIN'],
            ['name' => 'Super Administrateur']
        );

        Role::updateOrCreate(
            ['code' => 'STORE_OWNER'],
            ['name' => 'Propriétaire de boutique']
        );

        Role::updateOrCreate(
            ['code' => 'DELIVERY_AGENT'],
            ['name' => 'Agent de livraison']
        );
    }
}