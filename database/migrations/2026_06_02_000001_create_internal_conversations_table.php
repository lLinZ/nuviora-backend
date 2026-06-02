<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat interno 1:1 entre una Vendedora y una Agencia.
     * Un único hilo por par (vendedor_id, agency_id).
     */
    public function up(): void
    {
        Schema::create('internal_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendedor_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('agency_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['vendedor_id', 'agency_id']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_conversations');
    }
};
