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
        Schema::create('warehouse_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_physical')->default(true);
            $table->timestamps();
        });

        // Seed initial warehouse types
        DB::table('warehouse_types')->insert([
            [
                'code' => 'main',
                'name' => 'Almacén Principal',
                'description' => 'Almacén principal con el stock físico total',
                'is_physical' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'deliverer',
                'name' => 'Almacén de Repartidor',
                'description' => 'Inventario asignado a repartidores',
                'is_physical' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'repairement_storage',
                'name' => 'Almacén de Reparación',
                'description' => 'Productos en proceso de reparación o mantenimiento',
                'is_physical' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_types');
    }
};
