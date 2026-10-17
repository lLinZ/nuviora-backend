<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fran (2026-10-17, 10:54): "los horarios deberían ser por agencia, porque cada agencia puede tener un horario
     * distinto". La ciudad se queda solo con encendida o apagada (is_working); el horario es de la agencia
     * (users.agency_works_from / agency_works_until). En producción estaban vacías.
     */
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['works_from', 'works_until']);
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->time('works_from')->nullable()->after('is_working');
            $table->time('works_until')->nullable()->after('works_from');
        });
    }
};
