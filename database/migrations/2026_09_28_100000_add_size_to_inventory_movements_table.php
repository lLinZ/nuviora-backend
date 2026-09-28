<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarea 3a: cada salida o reingreso de stock de una orden guarda también la talla, para que al
     * devolverlo vuelva exactamente lo que salió (OrderStock). Los movimientos anteriores quedan sin talla.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('size', 50)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
