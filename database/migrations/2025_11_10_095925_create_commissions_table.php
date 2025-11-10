<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role'); // 'Vendedor','Repartidor','Gerente'
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('amount_usd', 8, 2);
            $table->string('currency')->default('USD'); // por si algún día sumas VES
            $table->decimal('rate', 10, 4)->default(1);   // tasa del día (ahora 1)
            $table->decimal('amount_local', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'order_id', 'role']); // 1 comisión por rol/orden/usuario
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
