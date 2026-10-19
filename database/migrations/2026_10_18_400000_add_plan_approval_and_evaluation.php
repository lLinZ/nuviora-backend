<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 5 de Fran, entrega 4: aprobación del plan (§89–§92), seguimiento y autoevaluación (§97–§104).
 * - Recomendaciones: lo aprobado junto a lo recomendado (§89) y la compra o el traslado que nació de cada una (§90, §92).
 * - Plan: quién y cuándo lo aprobó, y su evaluación cuando termina el horizonte (§98–§104). El resultado original no se
 *   toca (§96).
 * - Líneas: la predicción contra el consumo real, el quiebre, el stock final y el exceso (§97–§99).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_purchase_recommendations', function (Blueprint $table) {
            $table->integer('approved_qty')->nullable()->after('recommended_qty');
            $table->unsignedBigInteger('approved_supplier_id')->nullable()->after('approved_qty');
            $table->unsignedBigInteger('approved_receiving_warehouse_id')->nullable()->after('approved_supplier_id');
            $table->decimal('approved_unit_cost_usd', 12, 4)->nullable()->after('approved_receiving_warehouse_id');
            $table->date('approved_expected_at')->nullable()->after('approved_unit_cost_usd');
            $table->foreignId('inventory_purchase_id')->nullable()->after('approved_expected_at')->constrained('inventory_purchases')->nullOnDelete();
        });
        Schema::table('forecast_transfer_recommendations', function (Blueprint $table) {
            $table->integer('approved_qty')->nullable()->after('quantity');
            $table->unsignedBigInteger('approved_from_warehouse_id')->nullable()->after('approved_qty');
            $table->unsignedBigInteger('approved_to_warehouse_id')->nullable()->after('approved_from_warehouse_id');
            $table->foreignId('inventory_transfer_id')->nullable()->after('approved_to_warehouse_id')->constrained('inventory_transfers')->nullOnDelete();
        });
        Schema::table('inventory_forecasts', function (Blueprint $table) {
            $table->dateTime('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->dateTime('evaluated_at')->nullable()->after('approved_by');
            $table->longText('evaluation')->nullable()->after('evaluated_at');
        });
        Schema::table('inventory_forecast_lines', function (Blueprint $table) {
            $table->decimal('predicted_units', 10, 2)->nullable()->after('need_units');
            $table->decimal('actual_units', 10, 2)->nullable()->after('predicted_units');
            $table->boolean('stockout')->nullable()->after('actual_units');
            $table->integer('end_stock')->nullable()->after('stockout');
            $table->integer('excess_units')->nullable()->after('end_stock');
            $table->dateTime('evaluated_at')->nullable()->after('excess_units');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_forecast_lines', function (Blueprint $table) {
            $table->dropColumn(['predicted_units', 'actual_units', 'stockout', 'end_stock', 'excess_units', 'evaluated_at']);
        });
        Schema::table('inventory_forecasts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'evaluated_at', 'evaluation']);
        });
        Schema::table('forecast_transfer_recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_transfer_id');
            $table->dropColumn(['approved_qty', 'approved_from_warehouse_id', 'approved_to_warehouse_id']);
        });
        Schema::table('forecast_purchase_recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_purchase_id');
            $table->dropColumn(['approved_qty', 'approved_supplier_id', 'approved_receiving_warehouse_id', 'approved_unit_cost_usd', 'approved_expected_at']);
        });
    }
};
