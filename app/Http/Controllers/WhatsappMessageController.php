<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WhatsappMessage;
use App\Models\Order;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class WhatsappMessageController extends Controller
{
    public function index(Request $request, $orderId)
    {
        $perPage = $request->query('per_page', 20);
        $order = Order::findOrFail($orderId);
        $messages = WhatsappMessage::where('client_id', $order->client_id)
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

        $order = Order::with('client')->findOrFail($orderId);

        $renderedBody = $request->body;
        $components = [];
        $vars = $request->vars ?? [];
        $tpl = null;
        $service = new WhatsAppService();

        if ($request->filled('template_name')) {
            $tpl = \App\Models\WhatsappTemplate::where('name', $request->template_name)->first();
            if ($tpl) {
                $renderedBody = $tpl->render($vars);
                Log::critical("DEBUG_MSG_CTRL: Enviando {$request->template_name}", ['vars' => $vars]);
            }
        }

        $message = WhatsappMessage::create([
            'order_id' => $order->id,
            'client_id' => $order->client_id,
            'body' => $renderedBody,
            'is_from_client' => $request->input('is_from_client', false),
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        if (!$message->is_from_client) {
            if ($request->filled('template_name')) {
                if ($tpl) {
                    if (!empty($tpl->meta_components)) {
                        foreach ($tpl->meta_components as $component) {
                            $rawType = strtoupper($component['type'] ?? '');
                            if (!in_array($rawType, ['HEADER', 'BODY'])) continue;

                            $text = $component['text'] ?? '';
                            preg_match_all('/\{\{(\d+)\}\}/u', $text, $matches);
                            
                            $parameters = [];
                            if (!empty($matches[1])) {
                                foreach ($matches[1] as $placeholderNum) {
                                    $idx = (int)$placeholderNum - 1;
                                    $parameters[] = ['type' => 'text', 'text' => (string)($vars[$idx] ?? '')];
                                }
                            } else if ($rawType === 'HEADER' && count($vars) > 0) {
                                Log::critical("DEBUG_MSG_CTRL: Fallback Header para {$request->template_name}");
                                $parameters[] = ['type' => 'text', 'text' => (string)$vars[0]];
                            }

                            if (!empty($parameters)) {
                                $components[] = [
                                    'type' => strtolower($rawType),
                                    'parameters' => $parameters
                                ];
                            }
                        }
                    }
                } else {
                    if ($request->has('vars')) {
                        $parameters = array_map(fn($v) => ['type' => 'text', 'text' => $v], $request->vars);
                        $components[] = ['type' => 'body', 'parameters' => $parameters];
                    }
                }
                $result = $service->sendTemplate($order->client->phone, $request->template_name, 'es', $components);
            } else {
                $result = $service->sendMessage($order->client->phone, $message->body);
            }

            if ($result && isset($result['messages'][0]['id'])) {
                $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
            } else {
                $message->update(['status' => 'failed']);
            }
        }

        $message->refresh();
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    public function sendMedia(Request $request, $orderId)
    {
        $request->validate(['file' => 'required|file|max:15000']);
        $order = Order::with('client')->findOrFail($orderId);
        $file = $request->file('file');
        $path = $file->store('whatsapp_media', 'public');
        
        $service = new WhatsAppService();
        $mime = $file->getMimeType();
        $type = str_contains($mime, 'video') ? 'video' : (str_contains($mime, 'audio') ? 'audio' : 'image');
        
        $upload = $service->uploadMedia(storage_path('app/public/' . $path), $type);
        if ($upload && isset($upload['id'])) {
            $result = $service->sendMedia($order->client->phone, $upload['id'], $type, $request->caption);
            if ($result && isset($result['messages'][0]['id'])) {
                $msg = WhatsappMessage::create([
                    'order_id' => $order->id,
                    'client_id' => $order->client_id,
                    'is_from_client' => false,
                    'body' => $request->caption ?? "Archivo {$type}",
                    'media' => asset('storage/' . $path),
                    'status' => 'sent',
                    'message_id' => $result['messages'][0]['id'],
                    'sent_at' => now(),
                ]);
                event(new \App\Events\WhatsappMessageReceived($msg));
                return response()->json($msg, 201);
            }
        }
        return response()->json(['error' => 'Failed to send media'], 500);
    }

    public function markAsRead($orderId)
    {
        WhatsappMessage::where('order_id', $orderId)->where('is_from_client', true)->update(['status' => 'read']);
        $order = Order::find($orderId);
        if ($order) event(new \App\Events\OrderUpdated($order));
        return response()->json(['status' => 'success']);
    }
}
