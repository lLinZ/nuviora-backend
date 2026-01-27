<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Status;

class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            'Por aprobar entrega',
            'Por aprobar cambio de ubicacion',
            'Nuevo',
            'Asignado a vendedor',
            'Llamado 1',
            'Llamado 2',
            'Llamado 3',
            'Asignado a repartidor',
            'En ruta',
            'Programado para mas tarde',
            'Programado para otro dia',
            'Reprogramado',
            'Entregado',
            'Cancelado',
            'Rechazado',
            'Novedades',
            'Novedad Solucionada',
            'Asignar a agencia',
            'Activo',
            'Inactivo',
            'Sin Stock',
            'Confirmado',
            'Esperando Ubicacion'
        ];

        foreach ($statuses as $description) {
            Status::firstOrCreate(['description' => $description]);
        }
    }
}
