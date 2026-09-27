<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alerta de saturación (spec de la Líder §9): una fila cada vez que una vendedora pasa el 130 % de
     * la carga promedio de su grupo. Sirve para no repetir el aviso (vuelve a avisar solo si baja y
     * vuelve a subir) y como historial.
     */
    public function up(): void
    {
        Schema::create('saturation_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_group_id')->constrained('sales_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('load');
            $table->decimal('group_average', 6, 2);
            $table->decimal('over_pct', 6, 1);
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'cleared_at']);
            $table->index(['sales_group_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saturation_alerts');
    }
};
