<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPA financiero del Módulo 4 de Fran (§60 a §74) y el margen objetivo de cada producto (§66, R13).
 * - product_financial_settings: tipo de objetivo (% sobre ventas o sobre costo) y porcentaje. Sin fila: 20 % sobre
 *   ventas, lo que dijo Fran para empezar (R13).
 * - target_cpa_versions (§73): cada cálculo queda guardado con su período, R, V, P, efectividad, margen, Break-even,
 *   Target y Fully Loaded. Por producto + ciudad (§70, el valor principal) y por producto (§69, city_id vacío).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_financial_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('target_type', 10)->default('sales'); // sales, cost
            $table->decimal('target_pct', 5, 2)->default(20);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('target_cpa_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('orders_created')->default(0);
            $table->unsignedInteger('orders_delivered')->default(0);
            $table->decimal('effectiveness', 5, 2)->nullable();
            $table->decimal('revenue', 12, 2)->default(0);        // R (§64)
            $table->decimal('variable_costs', 12, 2)->default(0); // V (§64)
            $table->decimal('cogs', 12, 2)->default(0);
            $table->decimal('purchases', 10, 2)->default(0);      // P, Meta Purchases (§63)
            $table->decimal('spend', 12, 2)->default(0);
            $table->decimal('meta_cpa', 10, 2)->nullable();
            $table->string('target_type', 10);
            $table->decimal('target_pct', 5, 2);
            $table->decimal('break_even', 10, 2)->nullable();
            $table->decimal('target', 10, 2)->nullable();
            $table->decimal('fully_loaded', 10, 2)->nullable();
            $table->boolean('viable')->nullable();
            $table->string('trigger', 10)->default('auto'); // auto (miércoles, §61) o manual
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('computed_at');
            $table->index(['product_id', 'city_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_cpa_versions');
        Schema::dropIfExists('product_financial_settings');
    }
};
