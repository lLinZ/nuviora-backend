<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\WarehouseType;

class WarehouseTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'MAIN',
                'name' => 'Bodega Principal',
                'description' => 'Bodega central de almacenamiento',
                'is_physical' => true
            ],
            [
                'code' => 'DELIVERER',
                'name' => 'Bodega Movil (Repartidor)',
                'description' => 'Inventario en poder del repartidor',
                'is_physical' => true
            ],
            [
                'code' => 'AGENCY',
                'name' => 'Bodega de Agencia',
                'description' => 'Inventario físico en la agencia de delivery',
                'is_physical' => true
            ]
        ];

        foreach ($types as $type) {
            WarehouseType::updateOrCreate(['code' => $type['code']], $type);
        }
    }
}
