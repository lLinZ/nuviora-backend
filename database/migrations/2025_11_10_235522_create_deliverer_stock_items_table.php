<?php

// database/migrations/2025_01_01_120101_create_deliverer_stock_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deliverer_stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverer_stock_id')->constrained('deliverer_stocks')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->integer('qty_assigned')->default(0);   // lo que se le entregó del inventario general
            $table->integer('qty_delivered')->default(0);  // lo que marcó como entregado (o que se descuenta por órdenes)
            $table->integer('qty_returned')->default(0);   // lo devuelto al cerrar

            $table->timestamps();
            $table->unique(['deliverer_stock_id', 'product_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('deliverer_stock_items');
    }
};
