<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Trigger webhooks for a specific event and data.
     */
    public function trigger(string $eventType, $data)
    {
        $webhooks = Webhook::where('event_type', $eventType)
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            try {
                // If it's an order and the webhook has a specific status filter
                if ($data instanceof \App\Models\Order && $webhook->status_id && $webhook->status_id != $data->status_id) {
                    continue;
                }

                Http::post($webhook->url, [
                    'event' => $eventType,
                    'timestamp' => now()->toIso8601String(),
                    'data' => $data,
                ]);
                
                Log::info("Webhook sent successfully", ['url' => $webhook->url, 'event' => $eventType]);
            } catch (\Exception $e) {
                Log::error("Webhook failed", [
                    'url' => $webhook->url, 
                    'error' => $e->getMessage(),
                    'event' => $eventType
                ]);
            }
        }
    }
}
