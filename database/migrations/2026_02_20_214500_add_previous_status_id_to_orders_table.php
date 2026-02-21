<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Stores the status BEFORE the order was moved to "Sin Stock",
            // so we can restore it automatically when stock is recovered.
            $table->unsignedBigInteger('previous_status_id')->nullable()->after('status_id');
            $table->foreign('previous_status_id')->references('id')->on('statuses')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['previous_status_id']);
            $table->dropColumn('previous_status_id');
        });
    }
};
