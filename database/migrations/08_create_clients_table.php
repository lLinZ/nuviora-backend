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
        Schema::create('clients', function (Blueprint $table) {
            $table->id(); // PK autoincremental

            // IDs de Shopify
            $table->unsignedBigInteger('customer_id')->unique(); // ID del cliente en Shopify
            $table->string('customer_number')->nullable(); // redundante, por si lo usas en tu sistema

            // Datos básicos
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable()->index();

            // Dirección
            $table->string('country_name')->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
