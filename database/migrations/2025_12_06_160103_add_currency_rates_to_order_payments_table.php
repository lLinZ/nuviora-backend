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
        Schema::table('order_payments', function (Blueprint $table) {
            $table->decimal('usd_rate', 10, 4)->nullable()->after('reference');
            $table->decimal('eur_rate', 10, 4)->nullable()->after('usd_rate');
            $table->decimal('binance_usd_rate', 10, 4)->nullable()->after('eur_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropColumn(['usd_rate', 'eur_rate', 'binance_usd_rate']);
        });
    }
};
