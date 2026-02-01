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
        Schema::table('shops', function (Blueprint $table) {
            $table->time('auto_open_at')->nullable();
            $table->time('auto_close_at')->nullable();
            $table->boolean('auto_schedule_enabled')->default(false);
        });

        Schema::table('shop_user', function (Blueprint $table) {
            $table->boolean('is_default_roster')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['auto_open_at', 'auto_close_at', 'auto_schedule_enabled']);
        });

        Schema::table('shop_user', function (Blueprint $table) {
            $table->dropColumn('is_default_roster');
        });
    }
};
