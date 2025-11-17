<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_method', [
                'DOLARES_EFECTIVO',
                'BOLIVARES_TRANSFERENCIA',
                'BINANCE_DOLARES',
                'ZELLE_DOLARES',
            ])->nullable()->after('currency');

            // tasa usada si el pago fue en Bs
            $table->decimal('payment_rate', 10, 2)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_rate']);
        });
    }
};
