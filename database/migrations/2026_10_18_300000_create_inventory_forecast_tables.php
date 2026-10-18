<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 5 de Fran, entrega 3: cada plan de inventario que se genera queda guardado y nunca se sobrescribe (§96).
 * - inventory_forecasts: quién, cuándo, la cobertura pedida, la configuración usada, la versión del algoritmo y el
 *   resultado completo (compras, traslados, alertas, capital y balance final).
 * - inventory_forecast_lines: por producto + variante + almacén, el stock inicial, la demanda, el forecast, la cobertura y
 *   el estado (§79), para medir después si acertó (entrega 4, §97–§104).
 * - forecast_purchase_recommendations y forecast_transfer_recommendations: lo que el plan recomienda comprar y trasladar,
 *   que la entrega 4 aprueba (§89–§92).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('coverage_days', 5, 1);
            $table->decimal('safety_days', 5, 1);
            $table->string('algorithm_version', 20);
            $table->json('settings');
            $table->longText('result');
            $table->decimal('capital_required_usd', 14, 2)->default(0);
            $table->string('status', 15)->default('generated'); // generated; la entrega 4 agrega approved
            $table->timestamps();
        });

        Schema::create('inventory_forecast_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('inventory_forecasts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->integer('physical')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('available')->default(0);
            $table->integer('in_transit')->default(0);
            $table->integer('incoming')->default(0);
            $table->decimal('base_daily', 10, 3)->default(0);
            $table->decimal('forecast_daily', 10, 3)->default(0);
            $table->decimal('trend_pct', 8, 2)->nullable();
            $table->decimal('target_days', 6, 1)->default(0);
            $table->decimal('coverage_days', 8, 1)->nullable();
            $table->integer('need_units')->default(0);
            $table->string('light', 10);
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('confidence', 10);
            $table->json('detail');
            $table->timestamps();

            $table->index(['forecast_id', 'product_id']);
        });

        Schema::create('forecast_purchase_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('inventory_forecasts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->integer('ideal_qty');
            $table->integer('recommended_qty');
            $table->decimal('unit_cost_usd', 12, 4)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('receiving_warehouse_id')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('reason', 500)->nullable();
            $table->json('detail');
            $table->timestamps();
        });

        Schema::create('forecast_transfer_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('inventory_forecasts')->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->integer('quantity');
            $table->boolean('depends_on_purchase')->default(false);
            $table->string('reason', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_transfer_recommendations');
        Schema::dropIfExists('forecast_purchase_recommendations');
        Schema::dropIfExists('inventory_forecast_lines');
        Schema::dropIfExists('inventory_forecasts');
    }
};
