<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // Bucket for smart inbox classification
            $table->string('conversation_bucket')->default('follow_up')->after('status');
            // Track the actual last customer/agent message time (ignoring system events)
            $table->timestamp('last_message_at')->nullable()->after('conversation_bucket');
        });

        // Backfill conversation_bucket based on existing messages
        // Requires_attention: last message is from client
        // Follow_up: last message is from agent
        // Closed: conversation status is closed/resolved
        DB::statement("
            UPDATE whatsapp_conversations wc
            LEFT JOIN (
                SELECT client_id, is_from_client,
                       ROW_NUMBER() OVER (PARTITION BY client_id ORDER BY sent_at DESC, id DESC) AS rn
                FROM whatsapp_messages
            ) last_msg ON last_msg.client_id = wc.client_id AND last_msg.rn = 1
            SET wc.conversation_bucket = CASE
                WHEN wc.status = 'closed' OR wc.status = 'resolved' THEN 'closed'
                WHEN last_msg.is_from_client = 1 THEN 'requires_attention'
                ELSE 'follow_up'
            END
        ");

        // Backfill last_message_at
        DB::statement("
            UPDATE whatsapp_conversations wc
            INNER JOIN (
                SELECT client_id, MAX(sent_at) AS max_sent_at
                FROM whatsapp_messages
                GROUP BY client_id
            ) lm ON lm.client_id = wc.client_id
            SET wc.last_message_at = lm.max_sent_at
        ");
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn(['conversation_bucket', 'last_message_at']);
        });
    }
};
