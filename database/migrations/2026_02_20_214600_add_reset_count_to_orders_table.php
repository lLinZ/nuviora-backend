<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Counts how many times this order has been reset to "Nuevo" on shop close.
            // If this counter is > 0 when the shop closes again, the order is CANCELLED
            // instead of being reset again (it means nobody attended it in a full business day).
            $table->unsignedTinyInteger('reset_count')->default(0)->after('previous_status_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reset_count');
        });
    }
};
