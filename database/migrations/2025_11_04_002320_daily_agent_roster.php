<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_agent_rosters', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['date', 'agent_id']); // un agente por día
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('daily_agent_rosters');
    }
};
