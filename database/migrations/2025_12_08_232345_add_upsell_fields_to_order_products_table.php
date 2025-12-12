¿+<?php

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
        Schema::table('order_products', function (Blueprint $table) {
            // Ensure order_id has a standard index to support the foreign key
            // before we drop the composite unique index (which might be currently supporting it).
            $table->index('order_id');
            
            $table->dropUnique(['order_id', 'product_id']);
            
            $table->boolean('is_upsell')->default(false);
            $table->unsignedBigInteger('upsell_user_id')->nullable();
            $table->foreign('upsell_user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropForeign(['upsell_user_id']);
            $table->dropColumn(['is_upsell', 'upsell_user_id']);
        });
    }
};
