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
        Schema::table('clients', function (Blueprint $box) {
            $box->unsignedBigInteger('agent_id')->nullable()->after('id');
            $box->foreign('agent_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('users', function (Blueprint $box) {
            $box->boolean('is_active_crm')->default(false)->after('status_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $box) {
            $box->dropForeign(['agent_id']);
            $box->dropColumn('agent_id');
        });

        Schema::table('users', function (Blueprint $box) {
            $box->dropColumn('is_active_crm');
        });
    }
};
