<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobar el cierre completo con un botón (Fran, 15/10/2026: "que yo pueda aprobar el monto de la agencia y le dé a un
 * botón, o que pueda elegir otro monto y le dé a un botón"). Si elige otro monto, ese es el monto final del cierre, con
 * su motivo; deja de valer en cuanto el cierre vuelve a revisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_weekly_closures', function (Blueprint $table) {
            $table->decimal('manual_total', 12, 2)->nullable()->after('final_approved_total');
            $table->string('manual_reason', 500)->nullable()->after('manual_total');
        });
    }

    public function down(): void
    {
        Schema::table('agency_weekly_closures', function (Blueprint $table) {
            $table->dropColumn(['manual_total', 'manual_reason']);
        });
    }
};
