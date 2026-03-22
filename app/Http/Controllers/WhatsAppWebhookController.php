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
            $type        = $messageData['type'] ?? 'text';
            $messageId   = $messageData['id'];
            
            $body        = '';
            $mediaPath   = null;

            if ($type === 'text') {
                $body = $messageData['text']['body'] ?? '';
            } elseif ($type === 'location') {
                $lat = $messageData['location']['latitude'] ?? '';
                $lng = $messageData['location']['longitude'] ?? '';
                $body = "📍 Ubicación: https://www.google.com/maps?q={$lat},{$lng}";
            } elseif ($type === 'image' || isset($messageData['image'])) {
                $imageId = $messageData['image']['id'];
                $caption = $messageData['image']['caption'] ?? '';
                $token = env('WHATSAPP_ACCESS_TOKEN');
                
                if (!$token) {
                    $body = "⚠️ Error interno: WHATSAPP_ACCESS_TOKEN no existe o la caché de Laravel está bloqueando el env().";
                } else {
                    // 1. Request Media URL from Meta API
                    $response = \Illuminate\Support\Facades\Http::withToken($token)
                        ->get("https://graph.facebook.com/v17.0/{$imageId}");
                    
                    if ($response->successful() && isset($response['url'])) {
                        $mediaUrl = $response['url'];
                        
                        // 2. Download the actual binary file from Meta (Añadimos User-Agent para evitar bloqueos)
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)
                            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                            ->get($mediaUrl);
                        
                        if ($mediaResponse->successful()) {
                            // Save to public storage
                            $filename = 'whatsapp_media/' . uniqid('wa_') . '.jpg';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            
                            // 3. Assign to variables BEFORE saving the message to DB
                            $mediaPath = url('storage/' . $filename); 
                            $body = $caption;
                        } else {
                            // SI FALLA, ESCUPIMOS EL ERROR EN EL CHAT DE REACT
                            $body = "⚠️ Error descargando archivo de Meta: Status " . $mediaResponse->status() . " | " . $mediaResponse->body();
                        }
                    } else {
                        // SI FALLA LA URL, ESCUPIMOS EL ERROR EN EL CHAT DE REACT
                        $body = "⚠️ Error pidiendo URL a Meta: Status " . $response->status() . " | " . $response->body();
                    }
                }
            } elseif ($type === 'video' || isset($messageData['video'])) {
                $videoId = $messageData['video']['id'];
                $caption = $messageData['video']['caption'] ?? '';
                $token = env('WHATSAPP_ACCESS_TOKEN');
                
                if ($token) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v17.0/{$videoId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(60)->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_vid_') . '.mp4';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = $caption;
                        } else {
                            $body = "⚠️ Error descargando video de Meta: Status " . $mediaResponse->status();
                        }
                    }
                }
            } elseif ($type === 'audio' || $type === 'voice' || isset($messageData['audio']) || isset($messageData['voice'])) {
                $audioData = $messageData['audio'] ?? ($messageData['voice'] ?? []);
                $audioId   = $audioData['id'] ?? null;
                $token     = env('WHATSAPP_ACCESS_TOKEN');
                
                if ($token && $audioId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v17.0/{$audioId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_audio_') . '.ogg';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = '🎵 Nota de voz';
                        } else {
                            $body = "⚠️ Error descargando audio de Meta: Status " . $mediaResponse->status();
                        }
                    } else {
                        $body = "⚠️ Error obteniendo URL de audio de Meta: Status " . $response->status();
                    }
                } else {
                    $body = "🎵 Nota de voz recibida (sin archivo disponible)";
                }
            }

            // Normalize phone: strip non-digits, search by last 10 digits
            $cleanPhone = preg_replace('/[^0-9]/', '', $from);
            $last10     = substr($cleanPhone, -10);

            $order = \App\Models\Order::whereHas('client', function ($q) use ($last10) {
                $q->where('phone', 'like', "%{$last10}");
            })->orderBy('created_at', 'desc')->first();

            if ($order) {
                $receivedAt = now();
                $order->client()->update(['last_whatsapp_received_at' => $receivedAt]);

                $msg = \App\Models\WhatsappMessage::updateOrCreate(
                    [
                        'message_id' => $messageId,
                    ],
                    [
                        'order_id'      => $order->id,
                        'body'          => $body,
                        'media'         => $mediaPath,
                        'is_from_client'=> true,
                        'status'        => 'delivered',
                        'sent_at'       => $receivedAt,
                    ]
                );

                $msg->refresh();
                event(new \App\Events\WhatsappMessageReceived($msg));
            }
        }

        return response()->json(['status' => 'success']);
    }
}

