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
        Schema::create('orders', function (Blueprint $table) {
            $table->id(); // PK autoincremental

            // ID de Shopify
            $table->unsignedBigInteger('order_id')->unique(); // ID único de Shopify
            $table->string('order_number')->nullable();       // Número de orden (#1001)

            // Datos básicos
            $table->string('name')->nullable();               // Nombre interno (#1001)
            $table->decimal('current_total_price', 10, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->timestamp('processed_at')->nullable();

            // Relación con clientes
            $table->foreignId('client_id')
                ->constrained('clients')
                ->onDelete('cascade');

            // Relación con status
            $table->foreignId('status_id')
                ->constrained('statuses')
                ->onDelete('cascade');
            $table->timestamps(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
