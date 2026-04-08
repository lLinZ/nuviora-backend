<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WhatsappConversationController extends Controller
{
    /**
     * Devuelve la lista de clientes/contactos para la Sidebar de WhatsApp.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }
        
        $roleName = strtolower($user->role->description ?? '');
        // RESTRICCIÓN TOTAL: Solo el rol 'admin' ve todo.
        // Master, Gerente y Vendedor pasan por el filtro de privacidad.
        $isAdmin = ($roleName === 'admin');
        $search = $request->query('search');

        $query = Client::query();

        // 1. Filtrar por búsqueda si se proporciona
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        // 2. Filtrar visibilidad según el rol (Vendedoras solo ven lo suyo)
        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                // Opción A: Eres el dueño del PEDIDO MÁS RECIENTE del cliente
                $q->whereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereRaw('id = (SELECT id FROM orders o2 WHERE o2.client_id = orders.client_id ORDER BY created_at DESC LIMIT 1)');
                })
                // Opción B: No hay NINGÚN pedido aún, y tú eres el dueño del cliente o lead
                ->orWhere(function ($q2) use ($user) {
                    $q2->whereDoesntHave('orders')
                       ->where(function ($q3) use ($user) {
                           $q3->where('agent_id', $user->id)
                              ->orWhereHas('whatsappConversations', function ($cq) use ($user) {
                                  $cq->where('agent_id', $user->id)
                                     ->where('status', 'open');
                              });
                       });
                });
            });
        }

            $paginator = $query->withCount(['whatsappMessages as unread_count' => function ($q) {
                    $q->where('is_from_client', true)->where('status', '!=', 'read');
                }])
                ->with(['agent', 'latestOrder.agent', 'whatsappConversations' => function ($q) {
                    $q->where('status', 'open');
                }])
                ->with('latestWhatsappMessage')
                ->orderByRaw('COALESCE(last_interaction_at, last_whatsapp_received_at, created_at) DESC')
                ->paginate(50);

        $paginator->getCollection()->transform(function ($client) {
            $latestMessage = $client->latestWhatsappMessage;
            return [
                'id' => $client->id,
                'name' => $client->first_name . ' ' . $client->last_name,
                'phone' => $client->phone,
                'unread_count' => $client->unread_count,
                'is_window_open' => $client->isWhatsappWindowOpen(),
                'last_message' => $latestMessage ? $latestMessage->body : 'Sin mensajes',
                'last_message_date' => $latestMessage ? $latestMessage->sent_at : $client->created_at,
                'context' => [
                    'order' => $client->latestOrder,
                    'agent' => $client->agent,
                    'conversation' => $client->whatsappConversations->first(),
                ]
            ];
        });

        return response()->json($paginator);
    }

    /**
     * Trae todos los mensajes de un cliente.
     */
    public function show($clientId)
    {
        $messages = WhatsappMessage::where('client_id', $clientId)
            ->orderBy('created_at', 'asc')
            ->get();
        return response()->json($messages);
    }

    /**
     * Enviar mensaje centralizado
     */
    public function store(Request $request, $clientId)
    {
        $request->validate([
            'body' => 'required|string',
            'is_from_client' => 'boolean',
            'template_name' => 'nullable|string',
            'vars' => 'nullable|array',
        ]);

        $client = Client::findOrFail($clientId);
        
        if (!$request->input('is_from_client', false) && !$request->filled('template_name')) {
            if (!$client->isWhatsappWindowOpen()) {
                return response()->json(['message' => 'Ventana de 24 horas cerrada'], 403);
            }
        }

        $latestOrder = \App\Models\Order::where('client_id', $client->id)->orderBy('created_at', 'desc')->first();

        $message = WhatsappMessage::create([
            'order_id' => $latestOrder ? $latestOrder->id : null,
            'client_id' => $client->id,
            'body' => $request->body,
            'is_from_client' => $request->input('is_from_client', false),
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        if (!$message->is_from_client) {
            $service = new \App\Services\WhatsAppService();
            $components = [];

            if ($request->filled('template_name')) {
                $tpl = \App\Models\WhatsappTemplate::where('name', $request->template_name)->first();

                if ($tpl && !empty($tpl->meta_components)) {
                    $vars = $request->vars ?? [];
                    Log::critical("DEBUG_WA: Enviando plantilla estructurada " . $request->template_name);

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
                            Log::critical("DEBUG_WA: Fallback Header");
                            $parameters[] = ['type' => 'text', 'text' => (string)$vars[0]];
                        }

                        if (!empty($parameters)) {
                            $components[] = [
                                'type' => strtolower($rawType),
                                'parameters' => $parameters
                            ];
                        }
                    }
                } else {
                    // Fallback for simple/old templates or missing metadata
                    if ($request->has('vars')) {
                        Log::critical("DEBUG_WA: Fallback Body parameters for " . $request->template_name);
                        $parameters = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $request->vars);
                        $components[] = ['type' => 'body', 'parameters' => $parameters];
                    }
                }

                $result = $service->sendTemplate($client->phone, $request->template_name, 'es', $components);
            } else {
                $result = $service->sendMessage($client->phone, $message->body);
            }

            if ($result && isset($result['messages'][0]['id'])) {
                $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
            } else {
                $message->update(['status' => 'failed']);
                Log::error("EROR_WA: " . json_encode($result));
            }
        }

        $client->update(['last_interaction_at' => now()]);
        $message->refresh();
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    /**
     * Enviar multimedia
     */
    public function sendMedia(Request $request, $clientId)
    {
        $request->validate(['file' => 'required|file|max:16384']);
        $client = Client::findOrFail($clientId);
        
        $file = $request->file('file');
        $path = $file->store('whatsapp_media', 'public');
        $fullPath = storage_path('app/public/' . $path);
        
        $service = new \App\Services\WhatsAppService();
        $mime = $file->getMimeType();
        $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'document');
        
        $upload = $service->uploadMedia($fullPath, $type);
        if ($upload && isset($upload['id'])) {
            $send = $service->sendMedia($client->phone, $upload['id'], $type, $request->caption);
            if ($send && isset($send['messages'][0]['id'])) {
                $msg = WhatsappMessage::create([
                    'client_id' => $client->id,
                    'message_id' => $send['messages'][0]['id'],
                    'body' => $request->caption ?? "Archivo {$type}",
                    'is_from_client' => false,
                    'status' => 'sent',
                    'sent_at' => now(),
                    'media' => asset('storage/' . $path)
                ]);
                event(new \App\Events\WhatsappMessageReceived($msg));
                return response()->json($msg, 201);
            }
        }
        return response()->json(['message' => 'Error'], 500);
    }
}
