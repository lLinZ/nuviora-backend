<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Status;
use App\Models\WarehouseType;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['description' => 'Vendedor']);
        Role::firstOrCreate(['description' => 'Gerente']);
        Role::firstOrCreate(['description' => 'Admin']);
        Role::firstOrCreate(['description' => 'Repartidor']); // 👈 nuevo
        Role::firstOrCreate(['description' => 'Agencia']);
        Status::firstOrCreate(['description' => 'Nuevo']);
        Status::firstOrCreate(['description' => 'Asignado a vendedor']);
        Status::firstOrCreate(['description' => 'Llamado 1']);
        Status::firstOrCreate(['description' => 'Llamado 2']);
        Status::firstOrCreate(['description' => 'Llamado 3']);
        Status::firstOrCreate(['description' => 'Asignado a repartidor']);
        Status::firstOrCreate(['description' => 'En ruta']);
        Status::firstOrCreate(['description' => 'Programado para mas tarde']);
        Status::firstOrCreate(['description' => 'Programado para otro dia']);
        Status::firstOrCreate(['description' => 'Reprogramado']);
        Status::firstOrCreate(['description' => 'Entregado']);
        Status::firstOrCreate(['description' => 'Cancelado']);
        Status::firstOrCreate(['description' => 'Novedades']);
        Status::firstOrCreate(['description' => 'Novedad Solucionada']);
        Status::firstOrCreate(['description' => 'Asignar a agencia']);
        Status::firstOrCreate(['description' => 'Activo']); // Para usuarios y tiendas

        // Warehouse Types
        WarehouseType::firstOrCreate(['code' => 'MAIN'], [
            'name' => 'Bodega Principal',
            'description' => 'Bodega central de almacenamiento',
            'is_physical' => true
        ]);
        
        WarehouseType::firstOrCreate(['code' => 'DELIVERER'], [
            'name' => 'Bodega Movil (Repartidor)',
            'description' => 'Inventario en poder del repartidor',
            'is_physical' => true
        ]);

        WarehouseType::firstOrCreate(['code' => 'AGENCY'], [
            'name' => 'Bodega de Agencia',
            'description' => 'Inventario físico en la agencia de delivery',
            'is_physical' => true
        ]);
    }
}
