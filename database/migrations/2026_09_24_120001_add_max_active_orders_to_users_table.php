<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Máximo de órdenes activas de una vendedora, sumando todas las tiendas (Fran, segunda ronda §1).
     * null = sin tope. Activas = Asignado a vendedor y Llamado 1, 2 y 3.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_active_orders')->nullable()->after('can_handle_no_stock');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('max_active_orders');
        });
    }
};
