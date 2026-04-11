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
            if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
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
        try {
            $payload = $request->all();
            \Illuminate\Support\Facades\Log::info('WhatsApp Webhook Payload Received: ' . json_encode($payload));

            if (!isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
                return response()->json(['status' => 'no_messages']);
            }

            $messageData = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $from        = $messageData['from'] ?? '';
            $type        = $messageData['type'] ?? 'text';
            $messageId   = $messageData['id'] ?? null;
            
            $body        = '';
            $mediaPath   = null;

            // 1. Extraer contenido según el tipo
            if (isset($messageData['text'])) {
                $body = $messageData['text']['body'] ?? '';
            } elseif (isset($messageData['location'])) {
                $lat = $messageData['location']['latitude'] ?? '';
                $lng = $messageData['location']['longitude'] ?? '';
                $name = $messageData['location']['name'] ?? '';
                $addr = $messageData['location']['address'] ?? '';
                $body = "📍 Ubicación: https://www.google.com/maps?q={$lat},{$lng}";
                if ($name) $body .= "\nNombre: {$name}";
                if ($addr) $body .= "\nDir: {$addr}";
            } elseif (isset($messageData['interactive'])) {
                $iType = $messageData['interactive']['type'] ?? '';
                if ($iType === 'button_reply') {
                    $body = $messageData['interactive']['button_reply']['title'] ?? 'Botón presionado';
                } elseif ($iType === 'list_reply') {
                    $body = $messageData['interactive']['list_reply']['title'] ?? 'Opción de lista';
                    $desc = $messageData['interactive']['list_reply']['description'] ?? '';
                    if ($desc) $body .= " ({$desc})";
                }
            } elseif (isset($messageData['image'])) {
                $imageId = $messageData['image']['id'] ?? null;
                $caption = $messageData['image']['caption'] ?? '';
                $token = config('services.whatsapp.access_token');
                if ($token && $imageId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v21.0/{$imageId}");
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
            } elseif (isset($messageData['video'])) {
                $videoId = $messageData['video']['id'] ?? null;
                $caption = $messageData['video']['caption'] ?? '';
                $token = config('services.whatsapp.access_token');
                if ($token && $videoId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v21.0/{$videoId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(60)->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_vid_') . '.mp4';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = $caption ?: '🎥 Video';
                        }
                    }
                }
            } elseif (isset($messageData['audio']) || isset($messageData['voice'])) {
                $audioData = $messageData['audio'] ?? ($messageData['voice'] ?? []);
                $audioId   = $audioData['id'] ?? null;
                $token     = config('services.whatsapp.access_token');
                if ($token && $audioId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v21.0/{$audioId}");
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
            } elseif (isset($messageData['document'])) {
                $docId = $messageData['document']['id'] ?? null;
                $fileNameOrig = $messageData['document']['filename'] ?? 'documento';
                $token = config('services.whatsapp.access_token');
                if ($token && $docId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v21.0/{$docId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->timeout(60)->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $filename = 'whatsapp_media/' . uniqid('wa_doc_') . '_' . $fileNameOrig;
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = "📄 {$fileNameOrig}";
                        }
                    }
                }
            } elseif (isset($messageData['sticker'])) {
                $stickerId = $messageData['sticker']['id'] ?? null;
                $isAnimated = $messageData['sticker']['animated'] ?? false;
                $token = config('services.whatsapp.access_token');
                if ($token && $stickerId) {
                    $response = \Illuminate\Support\Facades\Http::withToken($token)->get("https://graph.facebook.com/v21.0/{$stickerId}");
                    if ($response->successful() && isset($response['url'])) {
                        $mediaResponse = \Illuminate\Support\Facades\Http::withToken($token)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->followRedirects()->timeout(60)->get($response['url']);
                        if ($mediaResponse->successful()) {
                            $prefix = $isAnimated ? 'wa_sticker_anim_' : 'wa_sticker_';
                            $filename = 'whatsapp_media/' . uniqid($prefix) . '.webp';
                            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $mediaResponse->body());
                            $mediaPath = url('storage/' . $filename);
                            $body = $isAnimated ? '🎬 Sticker animado' : '🎨 Sticker';
                        }
                    }
                }
            } elseif ($type === 'unsupported') {
                $body = '⚠️ Sticker o documento no soportado';
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

            // REGLA DE ASIGNACIÓN POR NÚMERO DE ORDEN EN EL MENSAJE
            if (!empty($body)) {
                // Buscar patrón #1234 o solo 1234 (mínimo 4 dígitos)
                if (preg_match('/#?(\d{4,10})/', $body, $matches)) {
                    $orderNum = $matches[1];
                    $foundOrder = \App\Models\Order::where('customer_number', $orderNum)
                        ->orWhere('id', $orderNum)
                        ->first();
                    
                    if ($foundOrder && $foundOrder->agent_id) {
                        $client->update(['agent_id' => $foundOrder->agent_id]);
                        \Illuminate\Support\Facades\Log::info("WA_HOOK: Re-asignado cliente {$client->id} al agente {$foundOrder->agent_id} por mención de orden #{$orderNum}");
                    }
                }
            }

            if (!$client->agent_id) { 
                \App\Services\CrmAssignmentService::assignNextAgent($client); 
            }

            $receivedAt = now();
            $client->update(['last_whatsapp_received_at' => $receivedAt, 'last_interaction_at' => $receivedAt]);

            // Link to active order if exists
            $order = \App\Models\Order::where('client_id', $client->id)
                ->whereHas('status', function($q) { 
                    $q->whereNotIn('description', ['Entregado', 'Cancelado', 'Rechazado']); 
                })
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

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('WA_HOOK_ERROR: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

}

