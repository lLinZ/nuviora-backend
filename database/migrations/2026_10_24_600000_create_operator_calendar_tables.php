<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística propia, calendario de operadores (§4.1):
 * - operator_patterns: un patrón recurrente por operador (por ejemplo 12 días trabajados y 4 libres) que cuenta días
 *   corridos desde su fecha de inicio, sin reiniciarse los lunes; los días que el centro cierra no se programan.
 * - operator_overrides: el cambio de un día (sustitución, vacaciones, ausencia o cambio de último minuto): ese día
 *   trabaja otro operador, o nadie.
 * - logistics_shifts.scheduled_user_id y closed_by: quién estaba programado cuando alguien abrió el turno y quién lo
 *   cerró (historial, §4.1: "quién estaba programado y quién efectivamente inició/cerró el turno").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->date('starts_on');
            $table->unsignedTinyInteger('days_on');
            $table->unsignedTinyInteger('days_off');
            $table->time('start_time')->nullable(); // null: la hora de apertura del centro
            $table->time('end_time')->nullable();   // null: la hora de cierre del centro
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('operator_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('user_id')->nullable()->constrained('users'); // quién trabaja ese día; null: nadie
            $table->string('reason', 20); // sustitucion | vacaciones | ausencia | cambio
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['center_id', 'date']);
        });

        Schema::table('logistics_shifts', function (Blueprint $table) {
            $table->foreignId('scheduled_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->after('ended_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logistics_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('scheduled_user_id');
        });
        Schema::dropIfExists('operator_overrides');
        Schema::dropIfExists('operator_patterns');
    }
};
