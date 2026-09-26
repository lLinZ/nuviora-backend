<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quién sacó o metió a una vendedora en el roster del día, en qué tienda y por qué
     * (spec de la Líder §6.1; Fran, segunda ronda §5). El roster sigue en daily_agent_rosters;
     * esta tabla es solo el historial.
     */
    public function up(): void
    {
        Schema::create('roster_changes', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active');
            $table->string('reason', 200)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 20)->nullable();
            $table->timestamps();

            $table->index(['date', 'shop_id']);
            $table->index(['agent_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_changes');
    }
};
