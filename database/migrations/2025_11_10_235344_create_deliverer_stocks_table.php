<?php

// database/migrations/2025_01_01_120100_create_deliverer_stocks_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deliverer_stocks', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->foreignId('deliverer_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
            $table->unique(['date', 'deliverer_id']); // 1 jornada abierta por día
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('deliverer_stocks');
    }
};
