<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'Admin',
            'Gerente',
            'Vendedor',
            'Repartidor',
            'Agencia',
            'Master',
            'Shopify'
        ];

        foreach ($roles as $description) {
            Role::firstOrCreate(['description' => $description]);
        }
    }
}
