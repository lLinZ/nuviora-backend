<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat interno por ORDEN. Un único hilo por order_id; los participantes
     * (vendedora = agent_id, agencia = agency_id) se derivan de la propia orden.
     */
    public function up(): void
    {
        Schema::create('internal_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique('order_id');
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_conversations');
    }
};
