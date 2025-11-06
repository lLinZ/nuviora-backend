<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Role;

class VendedoresSeeder extends Seeder
{
    public function run(): void
    {
        // Busca el rol "Vendedor" por descripción para no depender del ID
        $rolVendedor = Role::where('description', 'Vendedor')->first();

        if (!$rolVendedor) {
            $this->command->error("⚠️ El rol 'Vendedor' no existe. Corre primero el RolesSeeder.");
            return;
        }

        // Ajusta status_id si tu app usa otro estado por defecto (1 = Activo, por ejemplo)
        $STATUS_DEFECTO = 1;

        $vendedoras = [
            [
                'names'     => 'María',
                'surnames'  => 'González',
                'email'     => 'maria.vendedora@example.com',
                'phone'     => '0000000001',
                'address'   => 'Sin dirección',
            ],
            [
                'names'     => 'Carla',
                'surnames'  => 'Pérez',
                'email'     => 'carla.vendedora@example.com',
                'phone'     => '0000000002',
                'address'   => 'Sin dirección',
            ],
            [
                'names'     => 'Luisa',
                'surnames'  => 'Fernández',
                'email'     => 'luisa.vendedora@example.com',
                'phone'     => '0000000003',
                'address'   => 'Sin dirección',
            ],
            [
                'names'     => 'Daniela',
                'surnames'  => 'Torres',
                'email'     => 'daniela.vendedora@example.com',
                'phone'     => '0000000004',
                'address'   => 'Sin dirección',
            ],
            [
                'names'     => 'Patricia',
                'surnames'  => 'Rojas',
                'email'     => 'patricia.vendedora@example.com',
                'phone'     => '0000000005',
                'address'   => 'Sin dirección',
            ],
            [
                'names'     => 'Sofía',
                'surnames'  => 'Méndez',
                'email'     => 'sofia.vendedora@example.com',
                'phone'     => '0000000006',
                'address'   => 'Sin dirección',
            ],
        ];

        foreach ($vendedoras as $v) {
            User::firstOrCreate(
                ['email' => $v['email']], // evita duplicados si ya corriste el seeder antes
                [
                    'names'     => $v['names'],
                    'surnames'  => $v['surnames'],
                    'phone'     => $v['phone'],         // requerido por tu esquema
                    'address'   => $v['address'] ?? '', // por si address es NOT NULL
                    'theme'     => 'light',             // ajusta si tu columna no acepta null
                    'color'     => '#1976d2',           // color por defecto (MUI primary)
                    'password'  => Hash::make('123456'), // contraseña default
                    'role_id'   => $rolVendedor->id,
                    'status_id' => $STATUS_DEFECTO,
                ]
            );
        }

        $this->command->info('✅ Vendedoras creadas exitosamente.');
    }
}
