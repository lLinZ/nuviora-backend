<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo del reparto ponderado por tienda (una fila por pool, por ejemplo "shop:3").
     * Reemplaza a los settings round_robin_pointer_*: la fila se lee y escribe con bloqueo, así que
     * dos órdenes que entran en el mismo segundo ya no pueden caerle a la misma vendedora.
     */
    public function up(): void
    {
        Schema::create('assignment_pools', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_pools');
    }
};
