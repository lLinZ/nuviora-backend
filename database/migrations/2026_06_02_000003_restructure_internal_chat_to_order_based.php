<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reestructura el chat interno de "por par vendedora-agencia" a "POR ORDEN".
 *
 * Las migraciones 000001/000002 ya estaban registradas en entornos previos
 * (esquema viejo), por lo que editarlas no surtía efecto ("Nothing to migrate").
 * Esta migración con nombre nuevo SÍ se ejecuta y deja las tablas en su forma
 * final. Es idempotente: dropIfExists + create, sin importar el estado previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Hijo primero (FK), luego el padre.
        Schema::dropIfExists('internal_messages');
        Schema::dropIfExists('internal_conversations');

        Schema::create('internal_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique('order_id');
            $table->index('last_message_at');
        });

        Schema::create('internal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('internal_conversations')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_messages');
        Schema::dropIfExists('internal_conversations');
    }
};
