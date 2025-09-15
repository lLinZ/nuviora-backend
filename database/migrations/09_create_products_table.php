<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id(); // PK autoincremental

            // IDs de Shopify
            $table->unsignedBigInteger('product_id')->unique(); // ID del producto en Shopify
            $table->unsignedBigInteger('variant_id')->nullable()->index(); // variante (opcional)

            // Datos básicos
            $table->string('sku')->nullable()->index();
            $table->string('title');
            $table->string('name')->nullable();
            $table->decimal('price', 10, 2)->default(0);

            // Imagen
            $table->string('image')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
