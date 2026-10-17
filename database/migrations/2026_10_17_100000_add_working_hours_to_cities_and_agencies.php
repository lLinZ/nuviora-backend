<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fran (2026-10-17): apagar ciudades o agencias y ponerles horario ("Maracay todos los días cierra a las 5 de la
     * tarde, pero Valencia y Caracas no"). Una ciudad que no trabaja no pasa sus órdenes a vendedora (ver WorkingHours).
     * Sin horario = todo el día. Todo arranca encendido.
     */
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->boolean('is_working')->default(true)->after('agency_id');
            $table->time('works_from')->nullable()->after('is_working');
            $table->time('works_until')->nullable()->after('works_from');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('agency_is_working')->default(true)->after('max_active_orders');
            $table->time('agency_works_from')->nullable()->after('agency_is_working');
            $table->time('agency_works_until')->nullable()->after('agency_works_from');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['is_working', 'works_from', 'works_until']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['agency_is_working', 'agency_works_from', 'agency_works_until']);
        });
    }
};
