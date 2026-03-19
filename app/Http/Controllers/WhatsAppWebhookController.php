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
            $from        = $messageData['from']; // e.g. 584121234567
            $body        = $messageData['text']['body'] ?? '';
            $messageId   = $messageData['id'];

            // Normalize phone: strip non-digits, search by last 10 digits
            $cleanPhone = preg_replace('/[^0-9]/', '', $from);
            $last10     = substr($cleanPhone, -10);

            $order = \App\Models\Order::whereHas('client', function ($q) use ($last10) {
                $q->where('phone', 'like', "%{$last10}");
            })->orderBy('created_at', 'desc')->first();

            if ($order) {
                // ⏱️ Stamp the exact UTC time of this inbound message on the client.
                // This is the anchor that resets the Meta 24-hour free-text window.
                // We use now() (server UTC) — never the timestamp from the payload,
                // which can be delayed by Meta's infrastructure.
                $receivedAt = now();
                $order->client()->update(['last_whatsapp_received_at' => $receivedAt]);

                $msg = \App\Models\WhatsappMessage::updateOrCreate(
                    [
                        'message_id' => $messageId,
                    ],
                    [
                        'order_id'      => $order->id,
                        'body'          => $body,
                        'is_from_client'=> true,
                        'status'        => 'delivered',
                        'sent_at'       => $receivedAt,
                    ]
                );


                // 📡 Broadcast to frontend via WebSocket so the chat and the
                // 24-h window indicator update in real time
                event(new \App\Events\WhatsappMessageReceived($msg));
            }
        }

        return response()->json(['status' => 'success']);
    }
}

