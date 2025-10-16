<?php

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleBaseSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['description' => 'Vendedor']);
        Role::firstOrCreate(['description' => 'Gerente']);
        Role::firstOrCreate(['description' => 'Admin']);
        Role::firstOrCreate(['description' => 'Repartidor']); // 👈 nuevo
    }
}
