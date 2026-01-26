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
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('delivery_cost', 10, 2)->default(0)->after('role_id');
        });

        // Modificar el enum de earnings para incluir 'agencia'
        // Nota: En SQLite (para tests) esto no funcionaría igual, pero asumimos MySQL/MariaDB
        if (config('database.default') !== 'sqlite') {
            DB::statement("ALTER TABLE earnings MODIFY COLUMN role_type ENUM('vendedor', 'repartidor', 'gerente', 'agencia') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') !== 'sqlite') {
            DB::statement("ALTER TABLE earnings MODIFY COLUMN role_type ENUM('vendedor', 'repartidor', 'gerente') NOT NULL");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('delivery_cost');
        });
    }
};
