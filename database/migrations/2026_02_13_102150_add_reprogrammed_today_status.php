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
        \DB::table('statuses')->updateOrInsert(['description' => 'Reprogramado para hoy'], ['color' => '#EAB308']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \DB::table('statuses')->where('description', 'Reprogramado para hoy')->delete();
    }
};
