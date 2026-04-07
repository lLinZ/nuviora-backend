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
                
                if (!$token || !$imageId) {
                    $body = "⚠️ Error interno: Token no configurado o ID de imagen faltante.";
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
                $videoId = $messageData['video']['id'] ?? null;
                $caption = $messageData['video']['caption'] ?? '';
                $token = config('services.whatsapp.access_token');
                
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
                        } else {
                            $body = "⚠️ Error descargando audio de Meta: Status " . $mediaResponse->status();
                        }
                    } else {
                        $body = "⚠️ Error obteniendo URL de audio de Meta: Status " . $response->status();
                    }
                } else {
                    \Illuminate\Support\Facades\Log::error("DEBUG_WA: No se pudo descargar audio. Token: " . ($token ? 'OK' : 'FAIL') . " | AudioID: " . ($audioId ? 'OK' : 'FAIL'));
                    $body = "🎵 Nota de voz recibida (sin archivo disponible)";
                }
            }

            // Normalize phone: strip non-digits, search by last 10 digits
            $cleanPhone = preg_replace('/[^0-9]/', '', $from);
            $last10     = substr($cleanPhone, -10);

            // 1. Find or Create Client (Lead)
            $client = \App\Models\Client::where('phone', 'like', "%{$last10}")->first();
            if (!$client) {
                // Mocking Shopify ID as we do in manual orders
                $tempId = (int) (microtime(true) * 1000); 
                $client = \App\Models\Client::create([
                    'phone'           => '+' . $cleanPhone,
                    'customer_id'     => $tempId,
                    'customer_number' => $tempId,
                    'first_name'      => '📱 Lead ' . $last10,
                ]);
            }

            // [NEW] Lead Assignment
            if (!$client->agent_id) {
                \App\Services\CrmAssignmentService::assignNextAgent($client);
            }

            // 2. Update the "last received" vital for Meta's 24H window + sorting
            $receivedAt = now();
            $client->update([
                'last_whatsapp_received_at' => $receivedAt,
                'last_interaction_at' => $receivedAt
            ]);

            // 3. Determine if there is an active Order to attach the thread
            // [NEW] 🕵️ BUSCAR ID DE ORDEN EN EL TEXTO (Caso: cliente escribe de otro número)
            $order = null;
            if (preg_match('/(\d{10,15})/', $body, $matches)) {
                $orderIdFromText = $matches[1];
                $order = \App\Models\Order::where(function($q) use ($orderIdFromText) {
                    $q->where('order_id', $orderIdFromText)
                      ->orWhere('order_number', $orderIdFromText)
                      ->orWhere('name', 'LIKE', "%{$orderIdFromText}%");
                })->orderBy('created_at', 'desc')->first();

                if ($order && $order->client_id !== $client->id) {
                    \Illuminate\Support\Facades\Log::info("Vinculando mensaje de nuevo número a orden existente #{$order->name}");
                    // Opcionalmente podríamos vincular el numero nuevo al cliente original, o solo dejar el mensaje huerfano vinculado a la orden.
                    // Por ahora vinculamos el mensaje a la orden encontrada.
                }
            }

            // Si no se encontró por texto, buscar orden activa del remitente actual
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
                // Agent is automatically the one assigned to the order
                if ($order->agent_id) {
                    // Asegurar que la conversación huerfana (si existe) se asigne a quien tiene la orden
                    $conv = \App\Models\WhatsappConversation::where('client_id', $client->id)->where('status', 'open')->first();
                    if ($conv) {
                        $conv->update(['agent_id' => $order->agent_id]);
                    }
                }
            } else {
                // No active order -> Check for open Orphan Conversation
                $conversation = \App\Models\WhatsappConversation::where('client_id', $client->id)
                    ->where('status', 'open')
                    ->first();

                if (!$conversation) {
                    // No conversation -> ROUND ROBIN Allocation
                    // Find least busy agent active for whatsapp today
                    $availableRoster = \App\Models\DailyAgentRoster::where('is_whatsapp_active', true)
                        ->inRandomOrder()->first(); // Simple random allocation for now
                    
                    $agentId = null;
                    $shopId = null;
                    if ($availableRoster) {
                        $agentId = $availableRoster->agent_id;
                        $shopId = $availableRoster->shop_id;
                    } else {
                        // Fallback: Assign to Admin
                        $admin = \App\Models\User::whereHas('role', function($q){ $q->where('description', 'Admin'); })->first();
                        $agentId = $admin ? $admin->id : 1;
                    }

                    \App\Models\WhatsappConversation::create([
                        'client_id' => $client->id,
                        'agent_id' => $agentId,
                        'shop_id' => $shopId,
                        'status' => 'open'
                    ]);
                }
            }

            // 4. Create the message
            $msg = \App\Models\WhatsappMessage::updateOrCreate(
                ['message_id' => $messageId],
                [
                    'order_id'      => $orderId,
                    'client_id'     => $client->id,
                    'body'          => $body,
                    'media'         => $mediaPath,
                    'is_from_client'=> true,
                    'status'        => 'delivered',
                    'sent_at'       => $receivedAt,
                ]
            );

            $msg->refresh();
            // Load relationships for real-time frontend mapping
            $msg->load('client', 'order');
            
            event(new \App\Events\WhatsappMessageReceived($msg));
        }

        return response()->json(['status' => 'success']);
    }
}

