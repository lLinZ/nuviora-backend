<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Respuestas de Fran del 2026-10-09 al Módulo 2 (Meta Ads):
 * - pregunta 2: CTR y CPC siempre del enlace. Las reglas de tendencia que usaban los de todos los clics pasan a los
 *   del enlace;
 * - pregunta 5: la muestra limitada depende del gasto acumulado y de las compras, con umbrales configurables. Se agrega
 *   el gasto mínimo (USD); las compras mínimas siguen en threshold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_alert_rules', function (Blueprint $table) {
            $table->decimal('min_spend', 10, 2)->nullable()->after('threshold');
        });

        DB::table('meta_alert_rules')->where('metric', 'ctr')->update(['metric' => 'link_ctr']);
        DB::table('meta_alert_rules')->where('metric', 'cpc')->update(['metric' => 'link_cpc']);
    }

    public function down(): void
    {
        Schema::table('meta_alert_rules', function (Blueprint $table) {
            $table->dropColumn('min_spend');
        });
    }
};
