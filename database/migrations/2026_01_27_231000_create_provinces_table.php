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
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('delivery_cost_usd', 10, 2)->nullable();
            $table->foreignId('agency_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // Agregar province_id a orders
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('province_id')->nullable()->after('city_id')->constrained('provinces')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['province_id']);
            $table->dropColumn('province_id');
        });
        
        Schema::dropIfExists('provinces');
    }
};
