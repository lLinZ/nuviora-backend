<?php

// database/migrations/2025_01_01_000000_create_order_postponements_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_postponements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('reason')->nullable();
            $table->dateTime('scheduled_for'); // cuándo se reprograma
            $table->timestamps();
        });

        // (Opcional) si quieres tenerlo también denormalizado en la orden:
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('scheduled_for')->nullable()->after('status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_postponements');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('scheduled_for');
        });
    }
};
