<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends Controller
{
    /**
     * Webhook verification for Meta (GET)
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === env('WHATSAPP_VERIFY_TOKEN')) {
                return response($challenge, 200);
            }
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming notifications from Meta (POST)
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Check if it's a message event
        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $messageData = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $from = $messageData['from']; // 58412...
            $body = $messageData['text']['body'] ?? '';
            $messageId = $messageData['id'];

            // Find the most recent active order for this phone number
            // We search for clients with this phone (might need cleaner phone search)
            $cleanPhone = preg_replace('/[^0-9]/', '', $from);
            
            $order = \App\Models\Order::whereHas('client', function($q) use ($cleanPhone) {
                // Approximate search (Meta sends with country code, DB might have it or not)
                $q->where('phone', 'like', "%{$cleanPhone}%");
            })->orderBy('created_at', 'desc')->first();

            if ($order) {
                $msg = \App\Models\WhatsappMessage::create([
                    'order_id' => $order->id,
                    'message_id' => $messageId,
                    'body' => $body,
                    'is_from_client' => true,
                    'status' => 'delivered',
                    'sent_at' => now(),
                ]);

                // 📡 Broadcast to frontend via Reverb/WebSocket
                // Assuming we have an event for this
                event(new \App\Events\WhatsappMessageReceived($msg));
            }
        }

        return response()->json(['status' => 'success']);
    }
}

