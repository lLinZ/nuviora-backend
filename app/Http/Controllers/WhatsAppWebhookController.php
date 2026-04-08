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
        \Illuminate\Support\Facades\Log::info('WhatsApp Webhook Payload Received:', $payload);

        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $messageData = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $from        = $messageData['from'] ?? '';
            $type        = $messageData['type'] ?? 'text';
            $messageId   = $messageData['id'] ?? null;
            
            $body        = '';
            $mediaPath   = null;

            if ($type === 'text') {
                $body = $messageData['text']['body'] ?? '';
            } elseif ($type === 'image' || isset($messageData['image'])) {
                $imageId = $messageData['image']['id'] ?? null;
                $caption = $messageData['image']['caption'] ?? '';
                $token = config('services.whatsapp.access_token');
                if ($token && $imageId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v17.0/{$imageId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_img_') . '.jpg';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = $caption ?: '📷 Imagen';
                        }
                    }
                }
            } elseif ($type === 'sticker' || isset($messageData['sticker'])) {
                $stickerId = $messageData['sticker']['id'] ?? null;
                $token = config('services.whatsapp.access_token');
                if ($token && $stickerId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v17.0/{$stickerId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_sticker_') . '.webp';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = '🎨 Sticker';
                        }
                    }
                }
            }

            if (!$from || !$messageId) {
                return response()->json(['status' => 'ignored']);
            }

            // Client/Lead
            $cleanPhone = preg_replace('/[^0-9]/', '', $from);
            $last10     = substr($cleanPhone, -10);
            $client = \App\Models\Client::where('phone', 'like', "%{$last10}")->first();
            if (!$client) {
                $tempId = (int) (microtime(true) * 1000); 
                $client = \App\Models\Client::create([
                    'phone' => '+' . $cleanPhone,
                    'customer_id' => $tempId,
                    'customer_number' => $tempId,
                    'first_name' => '📱 Lead ' . $last10,
                ]);
            }

            if (!$client->agent_id) { \App\Services\CrmAssignmentService::assignNextAgent($client); }

            $receivedAt = now();
            $client->update(['last_whatsapp_received_at' => $receivedAt, 'last_interaction_at' => $receivedAt]);

            // Order Link
            $order = \App\Models\Order::where('client_id', $client->id)
                ->whereHas('status', function($q) { $q->whereNotIn('description', ['Entregado', 'Cancelado', 'Rechazado']); })
                ->orderBy('created_at', 'desc')->first();

            $orderId = $order ? $order->id : null;
            
            // Save Message
            $msg = \App\Models\WhatsappMessage::updateOrCreate(['message_id' => $messageId], [
                'order_id'      => $orderId,
                'client_id'     => $client->id,
                'body'          => $body ?: '',
                'media'         => $mediaPath ? ['link' => $mediaPath, 'type' => $type] : null,
                'is_from_client'=> true,
                'status'        => 'delivered',
                'sent_at'       => $receivedAt,
            ]);

            $msg->refresh()->load('client', 'order');
            event(new \App\Events\WhatsappMessageReceived($msg));
        }

        return response()->json(['status' => 'success']);
    }
}

