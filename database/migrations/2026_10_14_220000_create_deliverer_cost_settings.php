<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repartidores propios (Módulo 4 de Fran, R11): "ya no es necesariamente un costo fijo de 2,5 por entrega: puede ser por
 * tarifario o yo lo dejo fijo en un solo monto", "desde un almacén que yo también colocaría". Hoy no trabajan: es la
 * infraestructura. Sin fila, el repartidor cobra el monto general (finance.commission_deliverer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverer_cost_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('mode', 10)->default('fixed');            // fixed, tariff
            $table->decimal('fixed_amount', 10, 2)->nullable();       // null = el monto general
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete(); // de dónde sale (tarifario)
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverer_cost_settings');
    }
};
