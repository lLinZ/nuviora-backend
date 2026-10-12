<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El recordatorio de los cierres semanales pasa a las 11:00 (Fran, Módulo 4, R15, 2026-10-12: "a las 11 de la mañana,
 * que es una hora en donde estamos garantizados que todas las agencias están trabajando con el sistema abierto"). Las
 * agencias que tenían la hora por defecto (8:00) pasan a las 11:00; una hora puesta a mano no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_closure_settings', function (Blueprint $table) {
            $table->time('closing_time')->default('11:00:00')->change();
        });
        DB::table('agency_closure_settings')->where('closing_time', '08:00:00')->update(['closing_time' => '11:00:00', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('agency_closure_settings', function (Blueprint $table) {
            $table->time('closing_time')->default('08:00:00')->change();
        });
    }
};
