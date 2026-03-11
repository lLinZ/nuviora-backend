<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores the exact UTC timestamp of the last inbound WhatsApp message
     * received from each client. Used to enforce the Meta 24-hour messaging
     * window: free-form text messages are only allowed within 24 h of this
     * timestamp; after that only approved templates may be sent.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('last_whatsapp_received_at')
                  ->nullable()
                  ->after('address2')
                  ->comment('UTC timestamp of the last inbound WhatsApp message from this client (Meta 24-h window anchor)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('last_whatsapp_received_at');
        });
    }
};
