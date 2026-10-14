<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 3d del Módulo 4 de Fran: los demás pagos de la caja.
 * - expenses.financial_account_id: el gasto manual que se pagó sale de esa cuenta el día del gasto (§28). El que queda por
 *   pagar es una deuda (§44: empleados, herramientas y otras obligaciones).
 * - recurring_expense_payments: el pago de cada vencimiento de un gasto recurrente (§28: la caja usa la fecha real de pago;
 *   la rentabilidad lo sigue repartiendo en lo que cubre).
 * - order_refunds: la cuenta y el monto en su moneda (§77: el reembolso afecta la caja).
 * - accounts_receivable y receivable_collections: las otras cuentas por cobrar (§45), con sus cobros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('cash_movement_id')->constrained('financial_accounts');
        });

        Schema::create('recurring_expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_expense_id')->constrained('recurring_expenses')->restrictOnDelete();
            $table->date('due_date');                 // el vencimiento que se paga
            $table->date('paid_at');
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 5);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('amount_usd', 14, 2);
            $table->string('notes', 500)->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['recurring_expense_id', 'due_date']);
        });

        Schema::table('order_refunds', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('method')->constrained('financial_accounts');
            $table->decimal('amount', 14, 2)->nullable()->after('financial_account_id'); // en la moneda de la cuenta
            $table->string('currency', 5)->nullable()->after('amount');
            $table->decimal('rate', 14, 4)->nullable()->after('currency');
        });

        Schema::create('accounts_receivable', function (Blueprint $table) {
            $table->id();
            $table->string('debtor_name', 150);
            $table->string('description', 255);
            $table->string('currency', 5);
            $table->decimal('amount', 14, 2);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('amount_usd', 14, 2);
            $table->decimal('collected_amount', 14, 2)->default(0); // en la moneda de la cuenta por cobrar
            $table->decimal('collected_usd', 14, 2)->default(0);
            $table->date('issued_at');
            $table->date('due_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('receivable_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_receivable_id')->constrained('accounts_receivable')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 5);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('amount_usd', 14, 2);
            $table->decimal('applied_amount', 14, 2);
            $table->date('collected_at');
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts');
            $table->string('notes', 500)->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_collections');
        Schema::dropIfExists('accounts_receivable');
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
            $table->dropColumn(['amount', 'currency', 'rate']);
        });
        Schema::dropIfExists('recurring_expense_payments');
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
        });
    }
};
