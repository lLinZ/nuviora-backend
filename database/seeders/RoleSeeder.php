<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Status;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['description' => 'Vendedor']);
        Role::firstOrCreate(['description' => 'Gerente']);
        Role::firstOrCreate(['description' => 'Admin']);
        Role::firstOrCreate(['description' => 'Repartidor']); // 👈 nuevo
        Status::firstOrCreate(['description' => 'Nuevo']);
        Status::firstOrCreate(['description' => 'Asignado a vendedora']);
        Status::firstOrCreate(['description' => 'Llamado 1']);
        Status::firstOrCreate(['description' => 'Llamado 2']);
        Status::firstOrCreate(['description' => 'Llamado 3']);
        Status::firstOrCreate(['description' => 'Confirmado']);
        Status::firstOrCreate(['description' => 'Asignado a repartidor']);
        Status::firstOrCreate(['description' => 'En ruta']);
        Status::firstOrCreate(['description' => 'Programado para mas tarde']);
        Status::firstOrCreate(['description' => 'Programado para otro dia']);
        Status::firstOrCreate(['description' => 'Reprogramado']);
        Status::firstOrCreate(['description' => 'Cambio de ubicacion']);
        Status::firstOrCreate(['description' => 'Rechazado']);
        Status::firstOrCreate(['description' => 'Entregado']);
        Status::firstOrCreate(['description' => 'Cancelado']);
    }
}
