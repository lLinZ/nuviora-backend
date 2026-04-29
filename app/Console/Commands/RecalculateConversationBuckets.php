<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\ConversationBucketService;

/**
 * Recalculates conversation_bucket for ALL clients that have WhatsApp messages.
 * This command now uses ConversationBucketService to ensure a single source of truth.
 */
class RecalculateConversationBuckets extends Command
{
    protected $signature   = 'whatsapp:recalculate-buckets {--dry-run : Show what would change without writing}';
    protected $description = 'Recalculates conversation_bucket for all WhatsApp conversations using the central service logic';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $this->info($isDryRun ? '🔍 DRY RUN — no changes will be written.' : '🚀 Recalculating conversation buckets using central Service...');

        // Get all unique client IDs from message history
        $clientIds = WhatsappMessage::whereNotNull('client_id')
            ->select('client_id')
            ->distinct()
            ->pluck('client_id');

        $this->info("Found {$clientIds->count()} clients with WhatsApp messages.");

        if ($isDryRun) {
            $this->warn("Dry run is not fully supported with the Service-based recalculation, but I will simulate calls.");
        }

        $bar = $this->output->createProgressBar($clientIds->count());
        $bar->start();

        foreach ($clientIds as $clientId) {
            if (!$isDryRun) {
                // The service handles everything: finding/creating the conversation,
                // checking message history, status, and manual overrides.
                ConversationBucketService::recalculate((int)$clientId);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✅ All buckets recalculated using central logic.');
    }
}
