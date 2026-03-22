<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WhatsappMessageController extends Controller
{
    public function index(Request $request, $orderId)
    {
        $perPage = $request->query('per_page', 20);

        // Fetch the current order to get its client_id
        $order = \App\Models\Order::findOrFail($orderId);
        $clientId = $order->client_id;

        // Fetch messages for ALL orders belonging to this client
        $messages = \App\Models\WhatsappMessage::whereHas('order', function($q) use ($clientId) {
            $q->where('client_id', $clientId);
        })
        ->orderBy('created_at', 'desc')
        ->paginate($perPage);

        return response()->json($messages);
    }

    public function store(Request $request, $orderId)
    {
        $request->validate([
            'body' => 'required|string',
            'is_from_client' => 'boolean',
            'template_name' => 'nullable|string',
            'vars' => 'nullable|array',
        ]);

        $order = \App\Models\Order::with('client')->findOrFail($orderId);

        // 1. Persist to DB
        $message = \App\Models\WhatsappMessage::create([
            'order_id' => $order->id,
            'body' => $request->body,
            'is_from_client' => $request->input('is_from_client', false),
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        // 2. If it's from us (shop), send via Meta API
        if (!$message->is_from_client) {
            $service = new \App\Services\WhatsAppService();
            
            if ($request->filled('template_name')) {
                // Send as official Template
                $components = [];
                if ($request->has('vars')) {
                    $parameters = [];
                    foreach ($request->vars as $v) {
                        $parameters[] = ['type' => 'text', 'text' => $v];
                    }
                    $components[] = [
                        'type' => 'body',
                        'parameters' => $parameters
                    ];
                }
                $result = $service->sendTemplate($order->client->phone, $request->template_name, 'es', $components);
            } else {
                // Send as regular Text
                $result = $service->sendMessage($order->client->phone, $message->body);
            }

            if ($result && isset($result['messages'][0]['id'])) {
                $message->update([
                    'message_id' => $result['messages'][0]['id'],
                    'status' => 'sent'
                ]);
            } else {
                $message->update(['status' => 'failed']);
            }
        }

        // 3. Broadcast real-time (force db refresh to get real ID and confirmed message_id)
        $message->refresh();
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    public function sendMedia(Request $request, $orderId)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:jpeg,png,jpg,mp4,ogg,mp3,wav|max:15000',
            ]);

            $order = \App\Models\Order::with('client')->findOrFail($orderId);
            $rawPhone = $order->client->phone;

            // Limpiador básico de teléfono igual a WhatsAppService
            $phone = preg_replace('/[^0-9]/', '', $rawPhone);
            if (strpos($phone, '04') === 0) {
                $phone = '58' . substr($phone, 1);
            } elseif (strlen($phone) === 10 && strpos($phone, '4') === 0) {
                $phone = '58' . $phone;
            }

            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();
            $mime = $file->getMimeType();
            
            if (str_contains($mime, 'video')) {
                $type = 'video';
            } elseif (str_contains($mime, 'audio')) {
                $type = 'audio';
            } else {
                $type = 'image';
            }
            
            $filename = 'whatsapp_media/' . uniqid('wa_out_') . '.' . $extension;
            \Illuminate\Support\Facades\Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));
            $publicMediaUrl = url('storage/' . $filename);

            $phoneId = env('WHATSAPP_PHONE_NUMBER_ID');
            $token = env('WHATSAPP_ACCESS_TOKEN');

            $status = 'failed';
            $messageId = null;

            if ($phoneId && $token) {
                $response = \Illuminate\Support\Facades\Http::withToken($token)
                    ->withoutVerifying()
                    ->post("https://graph.facebook.com/v17.0/{$phoneId}/messages", [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $phone,
                        'type' => $type,
                        $type => [
                            'link' => $publicMediaUrl,
                        ]
                    ]);

                if ($response->successful()) {
                    $metaData = $response->json();
                    if (isset($metaData['messages'][0]['id'])) {
                        $status = 'sent';
                        $messageId = $metaData['messages'][0]['id'];
                    }
                } else {
                    \Illuminate\Support\Facades\Log::error('Meta Media Send Failed', ['response' => $response->body()]);
                }
            }

            $message = \App\Models\WhatsappMessage::create([
                'order_id' => $order->id,
                'body' => $type === 'video' ? '📽️ Video enviado' : ($type === 'audio' ? '🎵 Audio enviado' : '📷 Imagen enviada'),
                'media' => $publicMediaUrl,
                'is_from_client' => false,
                'status' => $status,
                'message_id' => $messageId,
                'sent_at' => now(),
            ]);

            $message->refresh();
            event(new \App\Events\WhatsappMessageReceived($message));

            return response()->json($message, 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SendMedia Fatal Error: " . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'class' => get_class($e)
            ], 500);
        }
    }

    public function markAsRead($orderId)

    {
        \App\Models\WhatsappMessage::where('order_id', $orderId)
            ->where('is_from_client', true)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        // Refresh order to broadcast new unread count if needed
        $order = \App\Models\Order::find($orderId);
        if ($order) {
            $order->load(['status', 'client', 'agent', 'agency', 'deliverer']);
            event(new \App\Events\OrderUpdated($order));
        }

        return response()->json(['status' => 'success']);
    }
}

