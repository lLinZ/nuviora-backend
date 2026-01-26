<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roleAdmin = \App\Models\Role::where('description', 'Admin')->first();
        $roleRepartidor = \App\Models\Role::where('description', 'Repartidor')->first();
        $statusActivo = \App\Models\Status::where('description', 'Activo')->first();

        // Admin default
        \App\Models\User::firstOrCreate(
            ['email' => 'admin@nuviora.com'],
            [
                'names' => 'Super',
                'surnames' => 'Admin',
                'password' => Hash::make('admin123'),
                'role_id' => $roleAdmin->id,
                'status_id' => $statusActivo->id,
                'phone' => '0000000000',
                'color' => '#0073ff',
                'theme' => 'light',
                'address' => 'Oficina Principal'
            ]
        );

        // Repartidor default
        \App\Models\User::firstOrCreate(
            ['email' => 'repartidor@nuviora.com'],
            [
                'names' => 'Juan',
                'surnames' => 'Repartidor',
                'password' => Hash::make('123456'),
                'role_id' => $roleRepartidor->id,
                'status_id' => $statusActivo->id,
                'phone' => '0000000000',
                'color' => '#0073ff',
                'theme' => 'light',
                'address' => 'Sin dirección'
            ]
        );
    }
}
