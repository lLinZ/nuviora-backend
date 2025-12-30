<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('warehouse_types')->insert([
            'code' => 'repartidor',
            'name' => 'Almacén de Repartidor',
            'description' => 'Almacén móvil asignado a un repartidor específico',
            'is_physical' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('warehouse_types')->where('code', 'repartidor')->delete();
    }
};
