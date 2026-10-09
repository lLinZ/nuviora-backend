<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de Meta Ads sin IA (documento de Fran del 2026-10-08, Módulo 2, §36), guardadas para cambiarlas sin tocar
 * código (§39: "de forma configurable para poder ajustarlas posteriormente sin modificar código"; §40: "Crear un motor
 * de reglas configurable"). Solo muestran señales: nunca pausan ni cambian nada (§37, §38).
 *
 * Tipos:
 * - spend_no_purchases (§39): gasto ≥ umbral × Target CPA sin compras. Se crean las dos del documento: ×1 alerta y
 *   ×2 crítica.
 * - limited_sample (§39): "muestra todavía limitada aunque exista alguna compra". El documento no dice cuántas
 *   compras, así que se crea desactivada y sin umbral.
 * - trend (§40): una métrica que sube o baja más de un % contra el período anterior. El documento dice
 *   "Posteriormente definiremos", así que no se crea ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);                  // spend_no_purchases, limited_sample, trend
            $table->string('metric', 24)->nullable();    // trend: cpa, cpm, ctr, link_ctr, hook_rate, hold_rate, frequency…
            $table->string('direction', 4)->nullable();  // trend: up o down
            $table->decimal('threshold', 10, 2)->nullable();
            $table->string('severity', 8)->default('alert'); // info, alert, critical
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('meta_alert_rules')->insert([
            ['type' => 'spend_no_purchases', 'threshold' => 1, 'severity' => 'alert', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'spend_no_purchases', 'threshold' => 2, 'severity' => 'critical', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'limited_sample', 'threshold' => null, 'severity' => 'info', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_alert_rules');
    }
};
