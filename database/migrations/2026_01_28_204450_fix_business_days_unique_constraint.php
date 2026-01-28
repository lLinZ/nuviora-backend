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
        Schema::table('business_days', function (Blueprint $table) {
            // 1. Drop the old single-column unique index
            // Typically named: table_column_unique
            $table->dropUnique('business_days_date_unique');

            // 2. Add the new composite unique index
            $table->unique(['date', 'shop_id'], 'business_days_date_shop_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_days', function (Blueprint $table) {
            // Restore original state
            $table->dropUnique('business_days_date_shop_unique');
            $table->unique('date', 'business_days_date_unique');
        });
    }
};
