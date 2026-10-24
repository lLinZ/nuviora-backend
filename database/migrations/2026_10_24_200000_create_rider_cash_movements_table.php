<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística propia, custodia de efectivo del rider (§17, §26 "Custodia de efectivo: cobros, entregas a operador,
 * diferencias"; R6). El saldo de cada rider es la suma de sus movimientos: cada cobro en efectivo suma y cada entrega al
 * operador resta lo que se recibió. Se lleva por moneda (dólares y bolívares) y en USD, con los bolívares a la tasa de
 * su orden, para el tope de USD 60.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_id')->constrained('users');
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->string('type', 20); // cobro | entrega
            $table->foreignId('logistics_order_id')->nullable()->constrained('logistics_orders')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('usd', 12, 2)->default(0);       // dólares en efectivo (+ cobro, − entrega)
            $table->decimal('ves', 14, 2)->default(0);       // bolívares en efectivo
            $table->decimal('total_usd', 12, 2)->default(0); // todo en USD, los bolívares a la tasa de su orden (R6)
            // En una entrega: lo que debía entregar y lo que entregó (§17 punto 4)
            $table->decimal('expected_usd', 12, 2)->nullable();
            $table->decimal('expected_ves', 14, 2)->nullable();
            $table->decimal('received_usd', 12, 2)->nullable();
            $table->decimal('received_ves', 14, 2)->nullable();
            $table->boolean('has_difference')->default(false);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['rider_id', 'created_at']);
            $table->unique(['logistics_order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_cash_movements');
    }
};
