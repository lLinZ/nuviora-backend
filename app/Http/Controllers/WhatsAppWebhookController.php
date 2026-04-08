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

        // Check if it's a message event
        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $messageData = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $from        = $messageData['from']; // e.g. 584121234567
            $type        = $messageData['type'] ?? 'text';
            $messageId   = $messageData['id'];
            
            $body        = '';
            $mediaPath   = null;

            // [LOG AGRESIVO] Ver estructura cruda para diagnosticar stickers animados
            \Illuminate\Support\Facades\Log::info("Webhook Message Data: " . json_encode($messageData));

            if ($type === 'text') {
                $body = $messageData['text']['body'] ?? '';
            } elseif ($type === 'location') {
                $lat = $messageData['location']['latitude'] ?? '';
                $lng = $messageData['location']['longitude'] ?? '';
                $body = "📍 Ubicación: https://www.google.com/maps?q={$lat},{$lng}";
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
                            $body = $caption;
                        }
                    }
                }
            } elseif ($type === 'video' || isset($messageData['video'])) {
                $videoId = $messageData['video']['id'] ?? null;
                $caption = $messageData['video']['caption'] ?? '';
                $token = config('services.whatsapp.access_token');
                if ($token && $videoId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v17.0/{$videoId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(60)->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_vid_') . '.mp4';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = $caption;
                        }
                    }
                }
            } elseif ($type === 'audio' || $type === 'voice' || isset($messageData['audio']) || isset($messageData['voice'])) {
                $audioData = $messageData['audio'] ?? ($messageData['voice'] ?? []);
                $audioId   = $audioData['id'] ?? null;
                $token     = config('services.whatsapp.access_token');
                if ($token && $audioId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v17.0/{$audioId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_audio_') . '.ogg';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = '🎵 Nota de voz';
                        }
                    }
                }
            } elseif ($type === 'sticker' || isset($messageData['sticker'])) {
                $type = 'sticker';
                $stickerId = $messageData['sticker']['id'] ?? null;
                $isAnimated = $messageData['sticker']['animated'] ?? false;
                $token     = config('services.whatsapp.access_token');
                \Illuminate\Support\Facades\Log::info("DETECCIÓN STICKER: ID {$stickerId}, Animado: " . ($isAnimated ? 'SI' : 'NO'));
                if ($token && $stickerId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v17.0/{$stickerId}");
                    if ($response->successful() && isset($response['url'])) {
                        \Illuminate\Support\Facades\Log::info("GET MEDIA URL: " . $response['url']);
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)
                            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                            ->followRedirects()
                            ->timeout(60)
                            ->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $prefix = $isAnimated ? 'wa_sticker_anim_' : 'wa_sticker_';
                            $filename = 'whatsapp_media/' . uniqid($prefix) . '.webp';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = $isAnimated ? '🎬 Sticker animado' : '🎨 Sticker';
                            \Illuminate\Support\Facades\Log::info("STICKER GUARDADO: {$filename} (" . strlen($mediaResponse->body()) . " bytes)");
                        } else {
                            $body = $isAnimated ? '🎬 Sticker animado (Descarga fallida)' : '🎨 Sticker (Descarga fallida)';
                            \Illuminate\Support\Facades\Log::error("ERROR DESCARGA MEDIA: Status " . $mediaResponse->status());
                        }
                    } else {
                        $body = '🎨 Sticker (URL no obtenida)';
                        \Illuminate\Support\Facades\Log::error("ERROR URL META: Status " . $response->status());
                    }
                } else {
                    $body = '🎨 Sticker (Sin ID/Token)';
                }
            }

            // Normalize phone: strip non-digits, search by last 10 digits
            $cleanPhone = preg_replace('/[^0-9]/', '', $from);
            $last10     = substr($cleanPhone, -10);

            $client = \App\Models\Client::where('phone', 'like', "%{$last10}")->first();
            if (!$client) {
                $tempId = (int) (microtime(true) * 1000); 
                $client = \App\Models\Client::create([
                    'phone'           => '+' . $cleanPhone,
                    'customer_id'     => $tempId,
                    'customer_number' => $tempId,
                    'first_name'      => '📱 Lead ' . $last10,
                ]);
            }

            if (!$client->agent_id) {
                \App\Services\CrmAssignmentService::assignNextAgent($client);
            }

            $receivedAt = now();
            $client->update([
                'last_whatsapp_received_at' => $receivedAt,
                'last_interaction_at' => $receivedAt
            ]);

            $order = null;
            if (preg_match('/(\d{10,15})/', $body, $matches)) {
                $orderIdFromText = $matches[1];
                $order = \App\Models\Order::where(function($q) use ($orderIdFromText) {
                    $q->where('order_id', $orderIdFromText)
                      ->orWhere('order_number', $orderIdFromText)
                      ->orWhere('name', 'LIKE', "%{$orderIdFromText}%");
                })->orderBy('created_at', 'desc')->first();
            }

            if (!$order) {
                $order = \App\Models\Order::where('client_id', $client->id)
                    ->whereHas('status', function($q) {
                        $q->whereNotIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                    })
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            $orderId = null;
            if ($order) {
                $orderId = $order->id;
                if ($order->agent_id) {
                    $conv = \App\Models\WhatsappConversation::where('client_id', $client->id)->where('status', 'open')->first();
                    if ($conv) { $conv->update(['agent_id' => $order->agent_id]); }
                }
            } else {
                $conversation = \App\Models\WhatsappConversation::where('client_id', $client->id)->where('status', 'open')->first();
                if (!$conversation) {
                    $availableRoster = \App\Models\DailyAgentRoster::where('is_whatsapp_active', true)->inRandomOrder()->first();
                    $agentId = $availableRoster ? $availableRoster->agent_id : 1;
                    $shopId = $availableRoster ? $availableRoster->shop_id : null;
                    \App\Models\WhatsappConversation::create([
                        'client_id' => $client->id,
                        'agent_id' => $agentId,
                        'shop_id' => $shopId,
                        'status' => 'open'
                    ]);
                }
            }

            $msg = \App\Models\WhatsappMessage::updateOrCreate(
                ['message_id' => $messageId],
                [
                    'order_id'      => $orderId,
                    'client_id'     => $client->id,
                    'body'          => $body,
                    'media'         => $mediaPath ? ['link' => $mediaPath, 'type' => $type] : null,
                    'is_from_client'=> true,
                    'status'        => 'delivered',
                    'sent_at'       => $receivedAt,
                ]
            );

            $msg->refresh()->load('client', 'order');
            event(new \App\Events\WhatsappMessageReceived($msg));
        }

        return response()->json(['status' => 'success']);
    }
}

