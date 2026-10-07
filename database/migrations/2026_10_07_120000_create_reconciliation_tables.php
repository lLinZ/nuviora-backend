<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación de pagos digitales con los extractos (documento de Fran del 2026-10-06, Módulo 1):
 * - reconciliation_days: una conciliación por fecha, independiente de las demás (§14).
 * - reconciliation_items: cada pago digital de ese día, con una copia de sus datos (§3, §11) y su estado (§20).
 * - bank_statements y bank_statement_rows: los extractos subidos (§16, §17) y sus ingresos, con la fila del archivo de
 *   la que salió cada uno. Los egresos no se guardan (§23).
 * - reconciliation_events: las acciones manuales y los extractos subidos o reemplazados (§26, §28).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            // pending: falta algún extracto; review: hay incidencias; completed: todo resuelto (§24, §25)
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statement_source_id')->constrained();
            $table->string('path'); // en el disco privado
            $table->string('original_name');
            $table->char('sha256', 64);
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedInteger('rows_read')->default(0);   // movimientos leídos del archivo
            $table->unsignedInteger('rows_credit')->default(0); // ingresos guardados
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // Reemplazar extracto (§26): el anterior queda guardado, con quién lo reemplazó y cuándo
            $table->foreignId('replaced_by_id')->nullable()->constrained('bank_statements')->nullOnDelete();
            $table->foreignId('replaced_by_user')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();
            $table->index(['reconciliation_day_id', 'statement_source_id']);
        });

        Schema::create('bank_statement_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line'); // fila del archivo (§17: rastrear qué fila originó una conciliación)
            $table->date('date')->nullable();
            $table->string('reference', 64)->nullable();      // tal como está en el archivo
            $table->string('reference_norm', 64)->nullable(); // solo dígitos y sin ceros a la izquierda (§19)
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 16, 2);
            $table->json('raw')->nullable(); // las celdas de la fila
            $table->timestamps();
            $table->index(['bank_statement_id', 'reference_norm']);
            $table->index(['bank_statement_id', 'amount']);
        });

        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receipt_check_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('payment_receipt_id')->nullable(); // sin FK: si se borra el comprobante, queda el dato
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Copia de los datos del pago al armar el día (§3, §11, §20)
            $table->string('order_name', 64)->nullable();
            $table->string('client_name', 160)->nullable();
            $table->string('method', 24);
            $table->string('currency', 8)->nullable();
            $table->decimal('amount', 16, 2)->nullable();
            $table->string('reference', 64)->nullable();
            $table->string('reference_norm', 64)->nullable();
            $table->date('paid_on')->nullable(); // la fecha que dice el comprobante
            $table->foreignId('company_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('statement_source_id')->nullable()->constrained()->nullOnDelete();
            // pending, matched, not_found, review, confirmed, not_received, linked (§20)
            $table->string('status', 16)->default('pending')->index();
            // Un movimiento del extracto sirve para un solo pago (§21)
            $table->foreignId('bank_statement_row_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('match_note', 255)->nullable(); // por qué quedó en ese estado
            $table->json('candidates')->nullable();        // movimientos posibles cuando requiere revisión
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('reconciliation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // upload, replace, confirm, not_received, link (§28)
            $table->string('action', 24);
            $table->foreignId('reconciliation_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_statement_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data')->nullable(); // antes y después
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_events');
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('bank_statement_rows');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('reconciliation_days');
    }
};
