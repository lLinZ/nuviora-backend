<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates conversation_bucket for ALL clients that have WhatsApp messages.
 * Run this once after deploying the bucket system, and again if data gets stale.
 *
 * Usage: php artisan whatsapp:recalculate-buckets
 */
class RecalculateConversationBuckets extends Command
{
    protected $signature   = 'whatsapp:recalculate-buckets {--dry-run : Show what would change without writing}';
    protected $description = 'Recalculates conversation_bucket for all WhatsApp conversations based on actual message history';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $this->info($isDryRun ? '🔍 DRY RUN — no changes will be written.' : '🚀 Recalculating conversation buckets...');

        // Get all clients that have at least one whatsapp message, excluding orphaned messages
        $clientIds = WhatsappMessage::whereNotNull('client_id')
            ->select('client_id')
            ->distinct()
            ->pluck('client_id');

        $this->info("Found {$clientIds->count()} clients with WhatsApp messages.");

        $bar = $this->output->createProgressBar($clientIds->count());
        $bar->start();

        $stats = ['requires_attention' => 0, 'follow_up' => 0, 'closed' => 0, 'created' => 0, 'skipped' => 0];

        foreach ($clientIds as $clientId) {
            // Find or create conversation
            $conv = WhatsappConversation::where('client_id', $clientId)->first();

            if (!$conv) {
                if (!$isDryRun) {
                    $conv = WhatsappConversation::create([
                        'client_id'           => $clientId,
                        'status'              => 'open',
                        'conversation_bucket' => 'follow_up',
                    ]);
                }
                $stats['created']++;
                $bar->advance();
                continue;
            }

            // Calculate correct bucket from message history
            $bucket = $this->calculateBucket($conv);

            // Get last relevant message time
            $lastMsg = WhatsappMessage::where('client_id', $clientId)
                ->whereIn('message_type', ['incoming_message', 'outgoing_agent_message', 'outgoing_automated_message'])
                ->latest('sent_at')
                ->first();

            if (!$isDryRun) {
                $conv->update([
                    'conversation_bucket' => $bucket,
                    'last_message_at'     => $lastMsg?->sent_at ?? $conv->updated_at,
                    // Ensure status is 'open' if it's not explicitly closed/resolved
                    'status'              => in_array($conv->status, ['closed', 'resolved']) ? $conv->status : 'open',
                ]);
            }

            $stats[$bucket]++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✅ Done!');
        $this->table(
            ['Bucket', 'Count'],
            [
                ['🔴 requires_attention', $stats['requires_attention']],
                ['🟡 follow_up',          $stats['follow_up']],
                ['✅ closed',             $stats['closed']],
                ['🆕 created',            $stats['created']],
            ]
        );
    }

    private function calculateBucket(WhatsappConversation $conv): string
    {
        // Closed status
        if (in_array($conv->status, ['closed', 'resolved'])) {
            return 'closed';
        }

        // Find the last relevant message (ignore system_event)
        $last = WhatsappMessage::where('client_id', $conv->client_id)
            ->whereIn('message_type', ['incoming_message', 'outgoing_agent_message', 'outgoing_automated_message'])
            ->latest('sent_at')
            ->first();

        // No messages at all → check is_from_client as fallback for old messages without message_type
        if (!$last) {
            $lastAny = WhatsappMessage::where('client_id', $conv->client_id)
                ->latest('sent_at')
                ->first();

            if (!$lastAny) return 'follow_up';
            return $lastAny->is_from_client ? 'requires_attention' : 'follow_up';
        }

        // Client wrote last → requires attention
        if ($last->message_type === 'incoming_message') {
            return 'requires_attention';
        }

        // Agent or automation wrote last → follow up
        return 'follow_up';
    }
}
