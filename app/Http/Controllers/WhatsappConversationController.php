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
                // Tienen una orden activa asignada a este vendedor
                $q->whereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereHas('status', function($sq) {
                           $sq->whereNotIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                       });
                })
                // O tienen una conversación huérfana asignada a este vendedor
                ->orWhereHas('whatsappConversations', function ($cq) use ($user) {
                    $cq->where('agent_id', $user->id)
                       ->where('status', 'open');
                });
            });
        }

        // 3. Traer relaciones necesarias y paginar
        $paginator = $query->withCount(['whatsappMessages as unread_count' => function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            }])
            ->with(['whatsappConversations' => function ($q) use ($isAdmin, $user) {
                $q->where('status', 'open');
                if (!$isAdmin) {
                    $q->where('agent_id', $user->id);
                }
            }])
            ->with('latestWhatsappMessage')
            ->with(['orders' => function ($q) {
                $q->with('status', 'shop', 'agent')->orderBy('created_at', 'desc')->limit(1);
            }])
            ->orderByRaw('COALESCE(last_whatsapp_received_at, created_at) DESC')
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
                'last_message' => $latestMessage ? $latestMessage->body : 'Sin mensajes',
                'last_message_date' => $latestMessage ? $latestMessage->sent_at : $client->created_at,
                'type' => $isOrphan ? 'lead' : 'order',
                'context' => [
                    'order' => $client->orders->first(),
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

        // 3. Update last_whatsapp_received_at to keep sorted in index
        $client->update(['last_whatsapp_received_at' => now()]);

        $message->refresh();
        $message->load('client', 'order');
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }
}
