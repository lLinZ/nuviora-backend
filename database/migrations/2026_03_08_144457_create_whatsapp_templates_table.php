<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Nombre técnico para la API de Meta (ej: 'confirmacion_orden')
            $table->string('label'); // Nombre amigable para el usuario (ej: 'Confirmar Orden')
            $table->text('body'); // El texto del mensaje
            $table->boolean('is_official')->default(false); // Si es un template registrado en Meta o solo texto libre
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
