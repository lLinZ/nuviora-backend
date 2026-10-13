<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario por lotes del Módulo 4 de Fran (§9 a §12, R5 y R7), entrega 2a. El libro de lotes reproduce
 * inventory_movements (App\Services\Finance\LotLedger); las ventas y el stock siguen como están.
 * - inventory_lots: el lote, por producto + variante (R7), con su costo unitario de origen (§9). Tipos: initial (el
 *   lote inicial del corte, a products.cost_usd, R5), purchase (una compra, 2b), pending_cost (entró sin costo: lo
 *   completa el Admin) y recovered (pieza que vuelve de un cambio, a $0: su costo ya se contó en la orden).
 * - inventory_lot_layers: dónde está cada parte del lote (§12): almacén, estado (available o review) y unidades. El lote
 *   conserva su identidad y su costo al trasladarse; cada llegada es una capa nueva, con su parte de logística (2c).
 * - inventory_lot_allocations: cada movimiento, cuántas unidades sacó o puso en cada capa (quantity con signo, sobre la
 *   capa). layer_id vacío = unidades que salieron sin lote (stock en negativo). is_opening: lo que las órdenes abiertas
 *   tenían fuera en el corte.
 * - inventory_movements.details: el ajuste guarda el total y las variantes de antes y después, para leerlo sin la nota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('kind', 20); // initial, purchase, pending_cost, recovered
            $table->foreignId('source_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->integer('initial_quantity')->default(0);
            $table->decimal('unit_cost_usd', 12, 4)->nullable(); // vacío = sin costo
            $table->string('cost_source', 20)->nullable();       // catalog, purchase, manual, recovered
            $table->dateTime('received_at');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variant_id']);
            $table->index(['source_movement_id']);
        });

        Schema::create('inventory_lot_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('state', 10)->default('available'); // available, review
            $table->integer('quantity')->default(0);
            $table->integer('opening_quantity')->default(0); // lo que tenía en el corte (para --replay)
            $table->decimal('landed_cost_per_unit', 12, 4)->default(0);
            $table->unsignedBigInteger('parent_layer_id')->nullable();
            $table->unsignedBigInteger('created_by_movement_id')->nullable(); // vacío = del corte
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'variant_id', 'state'], 'lot_layers_stock_idx');
            $table->index(['created_by_movement_id']);
        });

        Schema::create('inventory_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('layer_id')->nullable()->constrained('inventory_lot_layers')->cascadeOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->integer('quantity'); // sobre la capa: negativo sale, positivo entra
            $table->boolean('is_opening')->default(false);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['layer_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->json('details')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('details');
        });
        Schema::dropIfExists('inventory_lot_allocations');
        Schema::dropIfExists('inventory_lot_layers');
        Schema::dropIfExists('inventory_lots');
    }
};
