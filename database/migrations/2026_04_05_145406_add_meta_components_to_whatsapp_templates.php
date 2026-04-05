<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            // Stores the full Meta components array as JSON
            // Used to know which components have variables (header, body, etc.)
            $table->json('meta_components')->nullable()->after('is_official');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropColumn('meta_components');
        });
    }
};
