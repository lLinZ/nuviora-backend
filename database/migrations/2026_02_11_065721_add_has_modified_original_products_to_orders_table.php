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
            // 🔥 CLIENT REQUEST: Flag to track if original (non-upsell) products have been modified
            // When true, sellers cannot add upsells - they must contact admin
            $table->boolean('has_modified_original_products')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('has_modified_original_products');
        });
    }
};
