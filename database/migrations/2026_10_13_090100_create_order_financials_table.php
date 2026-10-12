<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La economía de cada orden (Módulo 4 de Fran, entrega 1): una fila por orden con su ingreso, su costo de producto, su
 * delivery y sus comisiones. De aquí leen la rentabilidad (§47), las cohortes (§2B, §59) y el CPA (§60-§74). La llena
 * App\Services\Finance\OrderEconomics; cuando algo de la orden cambia, la fila queda marcada (stale_at) y
 * `finance:rebuild` la recalcula al minuto siguiente. Las fechas son las del §3: creación y entrega.
 * Se marca en vez de encolar un trabajo por cambio: una orden que cambia cinco veces se calcula una vez, y un costo de
 * producto nuevo marca miles de órdenes con una sola consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_financials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('kind', 10)->default('normal'); // normal, exchange (cambio), return (devolución antigua)
            $table->foreignId('parent_order_id')->nullable();
            $table->date('created_date')->nullable()->index();   // §3: cohortes, Meta Ads, efectividad y CPA
            $table->date('delivered_date')->nullable()->index(); // §3: reconocimiento del ingreso
            $table->string('outcome', 15)->default('open')->index(); // delivered, not_delivered, open
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable()->index(); // el producto principal de la orden
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedInteger('units_main')->default(0);
            $table->unsignedInteger('units_upsell')->default(0);
            // §4, §7 y R1: ingreso en USD, solo entregada; el upsell aparte
            $table->decimal('revenue_main', 12, 2)->default(0);
            $table->decimal('revenue_upsell', 12, 2)->default(0);
            $table->decimal('revenue_refunded', 12, 2)->default(0); // R3: la devolución anula el ingreso
            // §9 y R5: costo de lo entregado, provisional (products.cost_usd) hasta que haya lotes
            $table->decimal('cogs_main', 12, 2)->default(0);
            $table->decimal('cogs_upsell', 12, 2)->default(0);
            $table->boolean('cogs_is_provisional')->default(true);
            $table->unsignedSmallInteger('cogs_missing_lines')->default(0); // líneas entregadas sin costo cargado
            // §5, §18 y §19: todas las carreras, entregada o no
            $table->decimal('delivery_cost', 10, 2)->default(0);
            $table->unsignedSmallInteger('delivery_trips')->default(0);
            $table->unsignedSmallInteger('delivery_final_trips')->default(0);   // con el costo aprobado en el cierre
            $table->unsignedSmallInteger('delivery_missing_trips')->default(0); // sin costo calculado
            $table->decimal('delivery_legacy_cost', 10, 2)->default(0); // pago por carrera de antes del Módulo 3
            // §22, §23: solo entregada
            $table->decimal('commission_seller', 10, 2)->default(0);
            $table->decimal('commission_upsell', 10, 2)->default(0);
            $table->decimal('commission_leader', 10, 2)->default(0);
            $table->decimal('commission_deliverer', 10, 2)->default(0); // repartidor propio (R11)
            $table->timestamp('stale_at', 6)->nullable()->index(); // con microsegundos: ver OrderEconomics::stamp()
            $table->timestamp('computed_at')->nullable();
        });

        // Todas las órdenes quedan por calcular: finance:rebuild las llena en los minutos siguientes
        // (con la hora de la app, no la de la base, que puede estar en otra zona)
        DB::table('order_financials')->insertUsing(['order_id', 'stale_at'], DB::table('orders')->selectRaw('id, ?', [now()->toDateTimeString()]));
    }

    public function down(): void
    {
        Schema::dropIfExists('order_financials');
    }
};
