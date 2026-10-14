<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras de inventario y deudas (Módulo 4 de Fran, entrega 2b; §9, §10, §16, §17, §44, R2, R6).
 * - inventory_purchases: la compra que nace en el ingreso de inventario (R6). Proveedor, producto, almacén, cantidad,
 *   costo total con su moneda y tasa (§32, R2), el equivalente en USD y el costo unitario = total / cantidad (§9). Al
 *   contado o a crédito, con vencimiento y condiciones (§16). La fecha es la del registro (§9).
 * - inventory_lots.purchase_id: cada lote de la compra (uno por variante, R7) sabe de qué compra viene.
 * - accounts_payable: lo que se debe (§44). Genérica: hoy la crean las compras; en la entrega 3, los cierres de las
 *   agencias (§101). El monto va en su moneda original, con su tasa y su equivalente en USD; lo pagado, en esa moneda.
 * - payable_payments: cada pago (§17), con su moneda, tasa, USD y lo que descuenta de la deuda. No cambia el costo del
 *   inventario. No se borra: se anula con motivo (§83). La cuenta de la empresa es opcional hasta la entrega 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('total_amount', 14, 2);
            $table->string('currency', 5);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('total_usd', 14, 2);
            $table->decimal('unit_cost_usd', 12, 4);
            $table->string('payment_type', 10); // cash, credit
            $table->dateTime('purchased_at');
            $table->date('due_date')->nullable();
            $table->string('terms', 500)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'purchased_at']);
            $table->index(['product_id', 'purchased_at']);
        });

        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->after('kind')->constrained('inventory_purchases')->nullOnDelete();
        });

        Schema::create('accounts_payable', function (Blueprint $table) {
            $table->id();
            $table->string('payee_type', 20); // supplier, agency, other
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payee_name')->nullable();
            $table->string('source_type', 30)->nullable(); // inventory_purchase, agency_closure, manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->string('currency', 5);
            $table->decimal('amount', 14, 2);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('amount_usd', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0); // en la moneda de la deuda
            $table->decimal('paid_usd', 14, 2)->default(0);    // lo que salió de caja, en USD a la tasa de cada pago
            $table->date('issued_at');
            $table->date('due_date')->nullable();
            $table->string('terms', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payee_type', 'supplier_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['due_date']);
        });

        Schema::create('payable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_payable_id')->constrained('accounts_payable')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 5);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('amount_usd', 14, 2);
            $table->decimal('applied_amount', 14, 2); // lo que descuenta de la deuda, en su moneda
            $table->date('paid_at');
            $table->foreignId('company_account_id')->nullable()->constrained('company_accounts')->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_payments');
        Schema::dropIfExists('accounts_payable');
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });
        Schema::dropIfExists('inventory_purchases');
    }
};
