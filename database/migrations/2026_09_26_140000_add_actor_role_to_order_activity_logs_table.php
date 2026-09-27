<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Con qué rol actuó quien hizo el cambio (spec de la Líder §15: historial con nombre y rol).
     * Se guarda al escribir: si mañana cambia de rol, el historial sigue diciendo lo que era.
     * Las filas anteriores quedan vacías.
     */
    public function up(): void
    {
        Schema::table('order_activity_logs', function (Blueprint $table) {
            $table->string('actor_role', 30)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_activity_logs', function (Blueprint $table) {
            $table->dropColumn('actor_role');
        });
    }
};
