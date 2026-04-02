<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Trigger webhooks for a specific event and model.
     */
    public function trigger(string $eventType, Order $order)
    {
        $webhooks = Webhook::where('event_type', $eventType)
            ->where('is_active', true)
            ->where(function ($query) use ($order) {
                $query->whereNull('status_id')
                      ->orWhere('status_id', $order->status_id);
            })
            ->get();

        foreach ($webhooks as $webhook) {
            try {
                Http::post($webhook->url, [
                    'event' => $eventType,
                    'timestamp' => now()->toIso8601String(),
                    'data' => $order->load(['status', 'client', 'agent', 'shop', 'products', 'city', 'province', 'payments']),
                ]);
                
                Log::info("Webhook sent successfully", ['url' => $webhook->url, 'order_id' => $order->id]);
            } catch (\Exception $e) {
                Log::error("Webhook failed", [
                    'url' => $webhook->url, 
                    'error' => $e->getMessage(),
                    'order_id' => $order->id
                ]);
            }
        }
    }
}
