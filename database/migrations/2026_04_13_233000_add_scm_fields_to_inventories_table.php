<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            // SCM: stock desglosado para calcular Stock Útil
            $table->unsignedInteger('reserved_stock')->default(0)->after('quantity')
                ->comment('Unidades comprometidas en pedidos pendientes');
            $table->unsignedInteger('defective_stock')->default(0)->after('reserved_stock')
                ->comment('Unidades dañadas o con defectos confirmados');
            $table->unsignedInteger('blocked_stock')->default(0)->after('defective_stock')
                ->comment('Unidades retenidas por auditoría u otro bloqueo temporal');
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn(['reserved_stock', 'defective_stock', 'blocked_stock']);
        });
    }
};
