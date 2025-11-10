<?php

// database/migrations/2025_01_01_000400_create_business_days_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();              // día de la jornada
            $table->timestamp('open_at')->nullable();    // cuándo se abrió
            $table->timestamp('close_at')->nullable();   // cuándo se cerró
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('business_days');
    }
};
