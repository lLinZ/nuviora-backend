<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Auth;

class WhatsappConversationController extends Controller
{
    /**
     * Devuelve la lista de clientes/contactos para la Sidebar de WhatsApp.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $isAdmin = $user->role && in_array($user->role->description, ['Admin', 'Gerente', 'Master', 'SuperAdmin']);
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

        // 2. Filtrar visibilidad según el rol
        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                // Asignado directamente al cliente (Lead o Re-asignación)
                $q->where('agent_id', $user->id)
                // O Tienen una orden activa asignada a este vendedor
                ->orWhereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereHas('status', function($sq) {
                           $sq->whereNotIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                       });
                });
            });
        }

        // 3. Traer relaciones necesarias y paginar
        $paginator = $query->withCount(['whatsappMessages as unread_count' => function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            }])
            ->with(['agent', 'whatsappConversations' => function ($q) use ($isAdmin, $user) {
                $q->where('status', 'open');
                if (!$isAdmin) {
                    $q->where('agent_id', $user->id);
                }
            }])
            ->with('latestWhatsappMessage')
            ->with(['orders' => function ($q) {
                $q->with('status', 'shop', 'agent')->orderBy('created_at', 'desc')->limit(1);
            }])
            ->orderByRaw('COALESCE(last_interaction_at, last_whatsapp_received_at, created_at) DESC')
            ->paginate(50);

        // 4. Formatear la colección interna del paginador
        $paginator->getCollection()->transform(function ($client) {
            $latestMessage = $client->latestWhatsappMessage;
            $isOrphan = $client->orders->isEmpty() || ($client->whatsappConversations->isNotEmpty());

            return [
                'id' => $client->id,
                'name' => $client->first_name . ' ' . $client->last_name,
                'phone' => $client->phone,
                'unread_count' => $client->unread_count,
                'is_window_open' => $client->isWhatsappWindowOpen(),
                'last_message' => $latestMessage ? $latestMessage->body : 'Sin mensajes',
                'last_message_date' => $latestMessage ? $latestMessage->sent_at : $client->created_at,
                'context' => [
                    'order' => $client->orders->first(),
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
     * Permite al administrador reasignar una conversación huérfana.
     */
    public function assignAgent(Request $request, $conversationId)
    {
        $request->validate([
            'agent_id' => 'required|exists:users,id'
        ]);

        $user = Auth::user();
        if (!$user->role || !in_array($user->role->description, ['Admin', 'Gerente', 'Master'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $conversation = WhatsappConversation::findOrFail($conversationId);
        $conversation->agent_id = $request->agent_id;
        $conversation->save();

        return response()->json([
            'status' => 'success',
            'conversation' => $conversation
        ]);
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
        
        // Validar ventana de 24 horas de Meta si no es un mensaje de plantilla
        if (!$request->input('is_from_client', false) && !$request->filled('template_name')) {
            if (!$client->isWhatsappWindowOpen()) {
                return response()->json([
                    'message' => 'La ventana de 24 horas de WhatsApp está cerrada para este cliente. Debe enviar una plantilla o esperar a que el cliente escriba.'
                ], 403);
            }
        }

        // Fetch order context if applies (para retrocompatibilidad si es necesario)
        $latestOrder = \App\Models\Order::where('client_id', $client->id)->orderBy('created_at', 'desc')->first();

        // 1. Persist to DB
        $message = WhatsappMessage::create([
            'order_id' => $latestOrder ? $latestOrder->id : null,
            'client_id' => $client->id,
            'body' => $request->body,
            'is_from_client' => $request->input('is_from_client', false),
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        // 2. Meta API
        if (!$message->is_from_client) {
            $service = new \App\Services\WhatsAppService();
            
            if ($request->filled('template_name')) {
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
                $result = $service->sendTemplate($client->phone, $request->template_name, 'es', $components);
            } else {
                $result = $service->sendMessage($client->phone, $message->body);
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

        // 3. Update last_interaction_at to keep sorted in index (WITHOUT opening Meta window)
        $client->update(['last_interaction_at' => now()]);

        $message->refresh();
        $message->load('client', 'order');
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    /**
     * Enviar multimedia (foto, video, audio) desde el CRM.
     */
    public function sendMedia(Request $request, $clientId)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,mp4,ogg,webm,mp3,wav|max:16384', // 16MB max
            'caption' => 'nullable|string'
        ]);

        $client = Client::findOrFail($clientId);
        
        // Validar ventana de 24 horas
        if (!$client->isWhatsappWindowOpen()) {
            return response()->json([
                'message' => 'La ventana de 24 horas de WhatsApp está cerrada para este cliente. No se puede enviar multimedia libre.'
            ], 403);
        }

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $filename = $file->getClientOriginalName();
        
        // Detección robusta de tipo para WhatsApp
        $type = $this->getWhatsAppMediaType($mime, $filename);

        // 1. Guardar localmente para persistencia en el CRM
        $path = $file->store('whatsapp_media', 'public');
        $fullLocalPath = storage_path('app/public/' . $path);
        $publicUrl = asset('storage/' . $path);

        // 2. Subir a Meta y Enviar
        $service = new \App\Services\WhatsAppService();
        $uploadResult = $service->uploadMedia($fullLocalPath, $type);

        if (!$uploadResult || !isset($uploadResult['id'])) {
            return response()->json(['message' => 'Error al subir archivo a WhatsApp'], 500);
        }

        $mediaId = $uploadResult['id'];
        $sendResult = $service->sendMedia($client->phone, $mediaId, $type, $request->caption);

        if (!$sendResult || !isset($sendResult['messages'][0]['id'])) {
            return response()->json(['message' => 'Error al enviar multimedia por WhatsApp'], 500);
        }

        // 3. Persistir en DB
        $latestOrder = \App\Models\Order::where('client_id', $client->id)->orderBy('created_at', 'desc')->first();
        
        $message = WhatsappMessage::create([
            'order_id' => $latestOrder ? $latestOrder->id : null,
            'client_id' => $client->id,
            'message_id' => $sendResult['messages'][0]['id'],
            'body' => $request->caption ?? ($type === 'audio' ? 'Mensaje de voz' : "Archivo {$type}"),
            'is_from_client' => false,
            'status' => 'sent',
            'sent_at' => now(),
            'media' => [
                'id' => $mediaId,
                'link' => $publicUrl,
                'mime_type' => $mime,
                'type' => $type
            ]
        ]);

        // Actualizar última interacción
        $client->update(['last_interaction_at' => now()]);

        $message->load('client', 'order');
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    private function getWhatsAppMediaType($mime, $filename = '')
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        
        // Si el nombre del archivo sugiere nota de voz o el mime es webm/ogg/opus, es AUDIO
        if (str_contains(strtolower($filename), 'voice-note') || 
            str_contains($mime, 'audio/') || 
            str_contains($mime, 'webm') || 
            str_contains($mime, 'ogg') || 
            str_contains($mime, 'opus')) {
            return 'audio';
        }

        if (str_starts_with($mime, 'video/mp4')) return 'video';
        if (str_starts_with($mime, 'video/')) return 'video';
        
        return 'document';
    }
}
