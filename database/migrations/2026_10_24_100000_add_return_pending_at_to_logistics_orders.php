<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística propia, retornos (§16.1): desde cuándo la pieza es un retorno pendiente. Con eso se sabe si venció el plazo
 * ("CONFIGURABLE — Plazo máximo permitido para un retorno pendiente… generar alerta de vencimiento"; R7: el mismo día,
 * antes del cierre del turno).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->timestamp('return_pending_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->dropColumn('return_pending_at');
        });
    }
};
