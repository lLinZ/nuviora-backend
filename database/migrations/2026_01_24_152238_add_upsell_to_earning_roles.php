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
        if (config('database.default') !== 'sqlite') {
            DB::statement("ALTER TABLE earnings MODIFY COLUMN role_type ENUM('vendedor', 'repartidor', 'gerente', 'agencia', 'upsell') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') !== 'sqlite') {
            DB::statement("ALTER TABLE earnings MODIFY COLUMN role_type ENUM('vendedor', 'repartidor', 'gerente', 'agencia') NOT NULL");
        }
    }
};
