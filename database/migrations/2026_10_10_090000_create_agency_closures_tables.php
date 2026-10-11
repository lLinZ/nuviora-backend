<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 3 de Fran (2026-10-09), fase 4: los cierres semanales de las agencias (§20 a §37, §46).
 * - La configuración de cada agencia: día y hora del aviso, activo o no (§21), la tolerancia (§30) y el mapeo de su
 *   archivo (§24).
 * - Un cierre por agencia y semana de lunes a domingo (§20), con sus totales, su estado (§36) y el pago (§37).
 * - Las versiones del archivo que sube la agencia o administración (§23, §40).
 * - Las filas leídas, con lo que traía el archivo sin tocar (§26), y su emparejamiento (§27, §28).
 * - Las discrepancias y cómo se resolvió cada una (§29 a §34).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_closure_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('closing_day')->default(3);       // ISO: 1 lunes … 7 domingo; 3 = miércoles
            $table->time('closing_time')->default('08:00:00');
            $table->boolean('reminder_active')->default(true);
            $table->decimal('tolerance_amount', 10, 2)->default(0);
            $table->decimal('tolerance_percent', 5, 2)->nullable();
            $table->json('file_mapping')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agency_weekly_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_start');                                   // lunes
            $table->date('period_end');                                     // domingo
            $table->string('status', 24)->default('pendiente_archivo');
            $table->unsignedInteger('trips_count')->default(0);
            $table->decimal('expected_total', 12, 2)->nullable();
            $table->decimal('reported_total', 12, 2)->nullable();
            $table->decimal('final_approved_total', 12, 2)->nullable();
            $table->json('summary')->nullable();
            $table->string('processing_error', 500)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('payment_amount', 12, 2)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('payment_method', 60)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('payment_receipt_path')->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'period_start']);
            $table->index(['status', 'period_start']);
        });

        Schema::create('agency_closure_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('closure_id')->constrained('agency_weekly_closures')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->boolean('is_current')->default(true);
            $table->json('extraction')->nullable();                         // cómo se leyó: mapeo, IA, avisos
            $table->unsignedInteger('rows_count')->nullable();
            $table->decimal('reported_total', 12, 2)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->unique(['closure_id', 'version']);
        });

        Schema::create('agency_closure_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('closure_id')->constrained('agency_weekly_closures')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('agency_closure_files')->cascadeOnDelete();
            $table->unsignedInteger('position');                            // fila del Excel o línea del PDF
            $table->json('raw_data');                                       // lo que traía el archivo, sin tocar (§26)
            $table->string('external_order_id', 80)->nullable();
            $table->string('order_key', 80)->nullable();                    // normalizado (§26)
            $table->boolean('repeat_mark')->default(false);                 // "#4747 (1)": la agencia la marca repetida
            $table->string('client_name', 160)->nullable();
            $table->string('client_name_key', 160)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('phone_key', 20)->nullable();
            $table->string('zone', 200)->nullable();
            $table->date('row_date')->nullable();
            $table->decimal('reported_cost', 10, 2)->nullable();
            $table->foreignId('matched_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('matched_trip_id')->nullable()->constrained('agency_trips')->nullOnDelete();
            $table->foreignId('delivery_route_id')->nullable()->constrained('order_delivery_routes')->nullOnDelete();
            $table->string('match_method', 16)->nullable();                 // order_id, phone, name_date, ai, manual
            $table->string('match_status', 20)->default('unmatched');       // matched, possible_match, unmatched, manually_matched
            $table->string('match_note', 500)->nullable();
            $table->json('candidate_trip_ids')->nullable();
            $table->decimal('expected_cost', 10, 2)->nullable();
            $table->decimal('approved_cost', 10, 2)->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->index(['closure_id', 'is_current']);
            $table->index('matched_trip_id');
        });

        Schema::create('agency_closure_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('closure_id')->constrained('agency_weekly_closures')->cascadeOnDelete();
            $table->foreignId('closure_row_id')->constrained('agency_closure_rows')->cascadeOnDelete();
            $table->string('kind', 20);                                     // amount, unmatched, ambiguous, duplicate, no_expected
            $table->decimal('expected_cost', 10, 2)->nullable();
            $table->decimal('reported_cost', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->nullable();
            $table->string('status', 12)->default('open');                  // open, resolved
            $table->string('resolution', 20)->nullable();                   // system, agency, manual, incident
            $table->decimal('approved_cost', 10, 2)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('notes')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->index(['closure_id', 'is_current', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_closure_discrepancies');
        Schema::dropIfExists('agency_closure_rows');
        Schema::dropIfExists('agency_closure_files');
        Schema::dropIfExists('agency_weekly_closures');
        Schema::dropIfExists('agency_closure_settings');
    }
};
