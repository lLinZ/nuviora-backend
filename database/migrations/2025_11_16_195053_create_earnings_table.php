<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('earnings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // tipo de ganancia: vendedor, repartidor, gerente
            $table->enum('role_type', ['vendedor', 'repartidor', 'gerente']);

            // monto en USD (base)
            $table->decimal('amount_usd', 10, 2);

            // por si luego manejas otras monedas
            $table->string('currency', 10)->default('USD');
            $table->decimal('rate', 10, 4)->default(1); // tasa usada en el cálculo

            // fecha efectiva de la ganancia (normalmente fecha de entrega)
            $table->date('earning_date');

            $table->timestamps();

            $table->index(['user_id', 'earning_date']);
            $table->index(['role_type', 'earning_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings');
    }
};
