<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística propia, incidencias viales compartidas (§15): lo que un rider ve en la vía (tráfico detenido, vía cerrada,
 * obra…), con su GPS y una caducidad configurable. Los demás riders y el operador marcan "sigue activo" (vuelve a
 * empezar la caducidad) o "ya despejado". No es una herramienta para evadir controles de la autoridad (§15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('road_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->foreignId('reported_by')->constrained('users');
            $table->string('type', 30);
            $table->string('comment', 300)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            // useCurrent: en MariaDB viejas (explicit_defaults_for_timestamp=0) una fecha NOT NULL sin default no se crea
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['center_id', 'expires_at']);
        });

        Schema::create('road_report_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('road_report_id')->constrained('road_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('vote', 20); // activo | despejado
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_report_votes');
        Schema::dropIfExists('road_reports');
    }
};
