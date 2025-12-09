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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_type_id')->constrained('warehouse_types')->onDelete('restrict');
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_main')->default(false);
            $table->timestamps();
        });

        // Seed main warehouse
        $mainTypeId = DB::table('warehouse_types')->where('code', 'main')->value('id');
        
        DB::table('warehouses')->insert([
            'warehouse_type_id' => $mainTypeId,
            'code' => 'MAIN-001',
            'name' => 'Almacén Principal',
            'description' => 'Almacén principal del sistema',
            'is_active' => true,
            'is_main' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
