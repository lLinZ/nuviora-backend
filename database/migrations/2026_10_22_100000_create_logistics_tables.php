<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logística propia + agencia de respaldo (Fran, 21/10/2026; respuestas R1–R8). Semana 1, pasos S1 y S2 de
     * tasks/logistica-propia/plan.md:
     * - el centro logístico de una ciudad, con su almacén, horario por día, excepciones por fecha y cutoff (§2, §4, §23);
     * - sus operadores (§3, §4: principal y suplente, los mismos permisos) y sus turnos (§4.2, §31.1);
     * - los parámetros con versión y fecha de vigencia (§31, §33), con los valores de R7;
     * - las alertas de la torre de control (§21, §25);
     * - el estado logístico de cada orden y su historial (§5, §20.1, §26). La orden sigue con sus estados de siempre (R3).
     * Nada queda atado a Valencia ni a un almacén (§30): el centro se crea desde la pantalla. Todo arranca apagado.
     */
    public function up(): void
    {
        Schema::create('logistics_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('address')->nullable();
            // Por día ISO (1 = lunes … 7 = domingo): ["08:00", "19:00"] o null si ese día no abre (§4, §31)
            $table->json('opening_hours')->nullable();
            // Hora límite para soltar órdenes nuevas (§4.2). R7: 18:00. Sin valor no se puede encender (§4.2: "obligatorio")
            $table->time('dispatch_cutoff')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('city_id');
        });

        Schema::create('logistics_center_operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Solo describe la cobertura habitual; los dos pueden todo (§4)
            $table->string('coverage', 20)->default('principal');
            $table->timestamps();
            $table->unique(['center_id', 'user_id']);
        });

        Schema::create('logistics_center_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_closed')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['center_id', 'date']);
        });

        Schema::create('logistics_settings', function (Blueprint $table) {
            $table->id();
            // null = los de todos los centros; un centro puede tener los suyos (§23: "por centro")
            $table->foreignId('center_id')->nullable()->constrained('logistics_centers')->cascadeOnDelete();
            $table->json('values');
            $table->timestamp('valid_from')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['center_id', 'valid_from']);
        });

        Schema::create('logistics_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            // §31.1: "cierre limpio" o "cerrar con pendientes/traspaso", con motivo
            $table->string('close_type', 20)->nullable();
            $table->text('close_reason')->nullable();
            $table->json('pending_at_close')->nullable();
            $table->timestamps();
            $table->index(['center_id', 'ended_at']);
        });

        Schema::create('logistics_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained('logistics_centers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->string('level', 10); // critical, red, warning, info (§25)
            $table->string('message');
            $table->json('data')->nullable();
            // Una alerta del mismo tipo para lo mismo no se repite mientras está abierta
            $table->string('dedupe_key')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['center_id', 'resolved_at']);
            $table->index('dedupe_key');
        });

        Schema::create('logistics_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->string('status', 30); // App\Constants\LogisticsStatus (§20.1)
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('overflow_reason')->nullable(); // §26: "motivo de overflow"
            // §5 y §26: el SLA arranca al liberar la orden en una ventana operativa y no se pausa
            $table->timestamp('released_at')->nullable();
            $table->timestamp('sla_started_at')->nullable();
            $table->timestamp('searching_started_at')->nullable();
            $table->timestamp('rider_confirmed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['center_id', 'status']);
        });

        Schema::create('logistics_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_order_id')->constrained('logistics_orders')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('reason')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('logistics_order_id');
        });

        // §3: el operador logístico, con los mismos permisos el principal y el suplente (§4)
        DB::table('roles')->insertOrIgnore(['description' => 'Operador logístico', 'created_at' => now(), 'updated_at' => now()]);

        // §31 con los valores de Fran (R7). Los que el documento deja pendientes y Fran no fijó quedan en null.
        DB::table('logistics_settings')->insert([
            'center_id' => null,
            'values' => json_encode(\App\Services\Logistics\LogisticsSettings::DEFAULTS),
            'valid_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_order_events');
        Schema::dropIfExists('logistics_orders');
        Schema::dropIfExists('logistics_alerts');
        Schema::dropIfExists('logistics_shifts');
        Schema::dropIfExists('logistics_settings');
        Schema::dropIfExists('logistics_center_exceptions');
        Schema::dropIfExists('logistics_center_operators');
        Schema::dropIfExists('logistics_centers');
        DB::table('roles')->where('description', 'Operador logístico')->delete();
    }
};
