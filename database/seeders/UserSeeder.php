<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\Status;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roleAdmin = Role::where('description', 'Admin')->first();
        $statusActivo = Status::where('description', 'Activo')->first();

        if (!$roleAdmin || !$statusActivo) {
            return;
        }

        // Default Admin User for Fresh Deployment
        User::updateOrCreate(
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
    }
}
