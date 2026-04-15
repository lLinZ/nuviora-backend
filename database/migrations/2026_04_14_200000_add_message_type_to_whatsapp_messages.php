<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('message_type')->default('outgoing_agent_message')->after('is_from_client');
            // Values: incoming_message | outgoing_agent_message | outgoing_automated_message | system_event
        });

        // Backfill: all client messages → incoming_message
        DB::statement("UPDATE whatsapp_messages SET message_type = 'incoming_message' WHERE is_from_client = 1");
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('message_type');
        });
    }
};
