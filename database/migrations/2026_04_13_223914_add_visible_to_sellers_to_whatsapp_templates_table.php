<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            // Default true: all existing templates remain visible to sellers unless explicitly hidden
            $table->boolean('visible_to_sellers')->default(true)->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropColumn('visible_to_sellers');
        });
    }
};
