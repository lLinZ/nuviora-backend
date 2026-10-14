<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 3c del Módulo 4 de Fran: los pagos salen de una cuenta de la caja, y las agencias.
 * - payable_payments y inventory_transfer_trips pasan de la cuenta del Módulo 1 (company_account_id) a la de la caja
 *   (financial_account_id). Los que ya tenían cuenta se pasan a la cuenta de la caja de esa cuenta.
 * - payable_payments.cash_movement_id: la parte de una liquidación de efectivo que se descontó de lo que se le debe a la
 *   agencia (R16, §103). Se anula con la liquidación.
 * - accounts_payable.voided_at: la deuda de un cierre que se reabrió (§101).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payable_payments', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('company_account_id')->constrained('financial_accounts');
            $table->foreignId('cash_movement_id')->nullable()->after('financial_account_id')->constrained('cash_movements')->nullOnDelete();
        });
        Schema::table('inventory_transfer_trips', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('company_account_id')->constrained('financial_accounts');
        });
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->dateTime('voided_at')->nullable()->after('terms');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
        });

        foreach (['payable_payments', 'inventory_transfer_trips'] as $t) {
            DB::table($t)->whereNotNull('company_account_id')->update([
                'financial_account_id' => DB::raw("(SELECT ca.financial_account_id FROM company_accounts ca WHERE ca.id = {$t}.company_account_id)"),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'void_reason']);
        });
        Schema::table('inventory_transfer_trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
        });
        Schema::table('payable_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_movement_id');
            $table->dropConstrainedForeignId('financial_account_id');
        });
    }
};
