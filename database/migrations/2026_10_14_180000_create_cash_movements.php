<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 3b del Módulo 4 de Fran: los movimientos de caja que se registran a mano (§37 a §41, §83): aporte y retiro del
 * propietario, transferencia interna (con su comisión, §40), cambio de moneda (§41) y ajuste de saldo. Cada uno da sus
 * movimientos en `financial_transactions` (CashLedger). La comisión es además un gasto (caso 25): `expenses.cash_movement_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->date('occurred_on');
            $table->foreignId('from_account_id')->nullable()->constrained('financial_accounts');
            $table->decimal('from_amount', 16, 2)->nullable();      // lo que sale, en la moneda de la cuenta de origen
            $table->foreignId('to_account_id')->nullable()->constrained('financial_accounts');
            $table->decimal('to_amount', 16, 2)->nullable();        // lo que entra, en la moneda de la cuenta de destino
            $table->decimal('rate', 14, 4)->nullable();             // unidades de la moneda que no es dólar por 1 USD (R2: a mano)
            $table->decimal('fee', 16, 2)->default(0);              // la comisión, en la moneda de la cuenta de origen (§40, §41)
            $table->decimal('amount_usd', 14, 2)->default(0);
            $table->foreignId('agency_id')->nullable()->constrained('users')->nullOnDelete(); // liquidación de efectivo (3c)
            $table->string('reference', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('receipt_path')->nullable();             // §79: adjunto opcional
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['kind', 'occurred_on']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('cash_movement_id')->nullable()->after('inventory_trip_id')->constrained('cash_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_movement_id');
        });
        Schema::dropIfExists('cash_movements');
    }
};
