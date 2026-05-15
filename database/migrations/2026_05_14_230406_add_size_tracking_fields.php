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
        Schema::table('products', function (Blueprint $table) {
            $table->json('available_sizes')->nullable()->after('showable_name');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->json('sizes_stock')->nullable()->after('quantity');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->string('size')->nullable()->after('showable_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_sizes');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('sizes_stock');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
