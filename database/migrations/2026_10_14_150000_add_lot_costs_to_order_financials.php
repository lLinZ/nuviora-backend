<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo por lotes en la economía de cada orden (Módulo 4 de Fran, entrega 2e; §9, §11, §14, §47).
 * - logistics_cost: la logística atribuible de lo entregado, la parte de los viajes de traslado de sus unidades (§14).
 * - cogs_source: de dónde sale el costo del producto: lots (de sus lotes, FIFO), catalog (products.cost_usd: de antes de
 *   los lotes o sin lote) o mixed. Lo que no es "lots" es provisional.
 * Las filas viejas se completan en el recálculo de la noche (finance:rebuild --mark-all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_financials', function (Blueprint $table) {
            $table->decimal('logistics_cost', 12, 2)->default(0)->after('cogs_upsell');
            $table->string('cogs_source', 10)->nullable()->after('cogs_is_provisional');
        });
    }

    public function down(): void
    {
        Schema::table('order_financials', function (Blueprint $table) {
            $table->dropColumn(['logistics_cost', 'cogs_source']);
        });
    }
};
