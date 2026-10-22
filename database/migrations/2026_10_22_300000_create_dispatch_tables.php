<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logística propia, semana 1, paso S4 (tasks/logistica-propia/plan.md): las rondas de asignación (§8, §24) y cómo se
     * asignó cada orden (§26: "Rondas: rider_ids notificados, sent_at, seen_at si existe, confirmed_at, round_number,
     * resultado"; "Asignación manual: operador, rider, hora, comentario/motivo"; "motivo de overflow").
     */
    public function up(): void
    {
        Schema::create('dispatch_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_order_id')->constrained('logistics_orders')->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->string('result', 20)->nullable(); // confirmada, sin_confirmar, cancelada
            $table->timestamps();
            $table->index(['logistics_order_id', 'number']);
        });

        Schema::create('dispatch_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('dispatch_rounds')->cascadeOnDelete();
            $table->foreignId('logistics_order_id')->constrained('logistics_orders')->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedInteger('eta_center_seconds')->nullable();
            $table->decimal('projected_minutes', 6, 1)->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
            $table->boolean('push_ok')->default(false);
            $table->string('push_error')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            // null = abierta; confirmada; sin_confirmar (venció la ronda); cubierta (otro la ganó); cancelada (la orden salió)
            $table->string('response', 20)->nullable();
            $table->timestamps();
            $table->index(['rider_id', 'response']);
            $table->index('logistics_order_id');
        });

        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->string('assigned_via', 20)->nullable()->after('overflow_reason'); // ronda, manual, agencia
            $table->foreignId('assigned_by')->nullable()->after('assigned_via')->constrained('users')->nullOnDelete();
            $table->string('assignment_note')->nullable()->after('assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['assigned_via', 'assignment_note']);
        });
        Schema::dropIfExists('dispatch_offers');
        Schema::dropIfExists('dispatch_rounds');
    }
};
