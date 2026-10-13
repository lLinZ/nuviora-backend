<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El día en que la rentabilidad cronológica cuenta los costos de una orden (Módulo 4 de Fran, §3 y §5): una entregada,
 * el día de la entrega (como su ingreso); una no entregada o todavía abierta, el día de su última carrera, porque el
 * delivery fallido es un costo de ese día. Sin carreras ni entrega, no tiene costos que contar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_financials', function (Blueprint $table) {
            $table->date('cost_date')->nullable()->after('delivered_date')->index();
        });
        // finance:rebuild la llena en los minutos siguientes
        DB::table('order_financials')->update(['stale_at' => now()->format('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        Schema::table('order_financials', function (Blueprint $table) {
            $table->dropColumn('cost_date');
        });
    }
};
