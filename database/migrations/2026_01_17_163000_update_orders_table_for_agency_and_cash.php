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
        Schema::table('orders', function (Blueprint $table) {
            // Agency and City association
            $table->foreignId('city_id')->nullable()->after('shop_id')->constrained('cities');
            $table->foreignId('agency_id')->nullable()->after('city_id')->constrained('users');
            $table->decimal('delivery_cost', 8, 2)->nullable()->after('agency_id');

            // Cash and Change (Vueltos)
            $table->decimal('cash_received', 8, 2)->nullable()->after('delivery_cost');
            $table->decimal('change_amount', 8, 2)->nullable()->after('cash_received');
            $table->enum('change_covered_by', ['agency', 'company', 'partial'])->nullable()->after('change_amount');
            $table->decimal('change_amount_company', 8, 2)->nullable()->after('change_covered_by');
            $table->decimal('change_amount_agency', 8, 2)->nullable()->after('change_amount_company');

            // Novedades
            $table->string('novedad_type')->nullable()->after('change_amount_agency');
            $table->text('novedad_description')->nullable()->after('novedad_type');
            $table->text('novedad_resolution')->nullable()->after('novedad_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropForeign(['agency_id']);
            $table->dropColumn([
                'city_id',
                'agency_id',
                'delivery_cost',
                'cash_received',
                'change_amount',
                'change_covered_by',
                'change_amount_company',
                'change_amount_agency',
                'novedad_type',
                'novedad_description',
                'novedad_resolution'
            ]);
        });
    }
};
