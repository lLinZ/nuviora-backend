<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alerta de distancia (Fran, 2026-10-12: "unos números absurdos de 80 dólares un delivery… porque la ubicación está mal",
 * y luego "coloquemos el límite de cada agencia a los pedidos que son más de 25 km"). Cada agencia tiene su límite, 25 km
 * por defecto. Una carrera que lo pasa queda "Distancia por revisar": guarda su costo, pero el cierre no lo cuenta hasta
 * que el Admin apruebe la distancia o corrija la ubicación. Las que ya pasan el límite quedan por revisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_closure_settings', function (Blueprint $table) {
            $table->decimal('review_distance_km', 7, 2)->default(25)->after('tolerance_percent');
        });
        Schema::table('order_delivery_routes', function (Blueprint $table) {
            $table->foreignId('distance_approved_by')->nullable()->after('review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('distance_approved_at')->nullable()->after('distance_approved_by');
        });

        // Con la configuración de hoy (todas en 25 km por defecto, o la que tenga cada agencia)
        $limits = DB::table('agency_closure_settings')->pluck('review_distance_km', 'agency_id');
        DB::table('order_delivery_routes')->where('calculation_status', 'calculated')->whereNotNull('distance_km')
            ->orderBy('id')->each(function ($r) use ($limits) {
                if ((float) $r->distance_km > (float) ($limits[$r->agency_id] ?? 25)) {
                    DB::table('order_delivery_routes')->where('id', $r->id)->update(['calculation_status' => 'distance_review', 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        DB::table('order_delivery_routes')->where('calculation_status', 'distance_review')->update(['calculation_status' => 'calculated']);
        Schema::table('order_delivery_routes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('distance_approved_by');
            $table->dropColumn('distance_approved_at');
        });
        Schema::table('agency_closure_settings', function (Blueprint $table) {
            $table->dropColumn('review_distance_km');
        });
    }
};
