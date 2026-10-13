<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos del Módulo 4 de Fran (§24 a §33) y la auditoría financiera (§82).
 * - Gasto manual (§24): concepto, categoría, monto, moneda, fecha, descripción, comprobante y, opcionales, producto,
 *   ciudad, almacén, agencia o proveedor. Cada uno guarda el monto original, la moneda, la tasa escrita a mano (R2) y el
 *   equivalente en USD (§31, §32). No se borra: se corrige con motivo o se anula (§83).
 * - Gasto recurrente (§25, §26): semanal, mensual, anual o cada N días, con su día de pago. El monto vive en versiones
 *   con fecha de inicio (§27): un aumento no cambia los meses anteriores. Activar o desactivar también es una versión.
 * - financial_audit_logs (§82): usuario, fecha y hora, valor anterior, valor nuevo y motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->string('frequency', 10); // weekly, monthly, annual, custom
            $table->unsignedSmallInteger('interval_days')->nullable(); // custom: cada cuántos días
            $table->unsignedTinyInteger('payment_day')->nullable();   // weekly: 1 (lunes) a 7; monthly: 1 a 31
            $table->date('payment_date')->nullable();                 // annual: el día del año; custom: un día de pago
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recurring_expense_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_expense_id')->constrained('recurring_expenses')->cascadeOnDelete();
            $table->date('valid_from');
            $table->boolean('is_active')->default(true);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 4);
            $table->decimal('rate', 14, 4)->default(1); // unidades de la moneda por 1 USD, escrita a mano (R2)
            $table->decimal('amount_usd', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['recurring_expense_id', 'valid_from'], 'recurring_versions_from_unique');
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('concept', 150);
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 4);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('amount_usd', 12, 2);
            $table->date('expense_date')->index();
            $table->text('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('recurring_expense_id')->nullable()->constrained('recurring_expenses')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Las categorías de los ejemplos de Fran (§13, §24, §25); se pueden agregar más desde la pantalla
        $now = now();
        DB::table('expense_categories')->insert(array_map(fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now], [
            'Software y herramientas',
            'Personal y servicios',
            'Formación',
            'Reparaciones y mantenimiento',
            'Transporte / logística de inventario',
            'Compras extraordinarias',
            'Otros',
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('recurring_expense_versions');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('financial_audit_logs');
    }
};
