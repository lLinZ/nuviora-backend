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
        Schema::create('order_tracking_comprehensive_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_status_id')->nullable()->constrained('statuses')->onDelete('set null');
            $table->foreignId('to_status_id')->constrained('statuses')->onDelete('cascade');
            $table->foreignId('seller_id')->nullable()->constrained('users')->onDelete('set null')->comment('Vendedora encargada en el momento del cambio');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null')->comment('Usuario que hizo el cambio');
            $table->boolean('was_unassigned')->default(false);
            $table->boolean('was_reassigned')->default(false);
            $table->foreignId('previous_seller_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_tracking_comprehensive_logs');
    }
};
