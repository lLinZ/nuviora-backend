<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Order;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\ConversationBucketService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp CRM v2 — Lógica de permisos simplificada y robusta.
 *
 * REGLA DE ORO:
 *  - Un agente ve un chat si la ÚLTIMA ORDEN del cliente tiene agent_id = user.id
 *  - Si el cliente NO tiene órdenes (lead) y clients.agent_id = user.id, también lo ve.
 *  - Admin/Gerente ven TODO.
 *
 * Esta regla es simple, escalable y se aplica igual en TODOS los métodos.
 */
class WhatsappCrmController extends Controller
{
    // ─── Roles que ven todo sin restricción ──────────────────────────────────
    private const SUPER_ROLES = ['admin', 'manager', 'gerente', 'master'];

    /**
     * Determina si el usuario autenticado tiene rol de super-admin.
     */
    private function isAdmin(): bool
    {
        $user = Auth::user();
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }
        $roleName = $user->role ? strtolower(trim($user->role->description)) : '';
        return in_array($roleName, self::SUPER_ROLES);
    }

    /**
     * Aplica el filtro de visibilidad al query de Client.
     * Centralizado para ser reutilizado en todos los métodos.
     */
    private function applyVisibilityScope($query, $userOrId): void
    {
        $userId = is_object($userOrId) ? $userOrId->id : $userOrId;
        
        // ── REGLA SIMPLE Y A PRUEBA DE FALLOS ───────────────────────────────
        $query->where(function ($q) use ($userId) {
            // A. Clientes con órdenes: la orden más reciente pertenece al agente explícitamente
            $q->whereHas('orders', function ($oq) use ($userId) {
                $oq->where('agent_id', $userId)
                   ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
            })
            // B. Clientes donde el agente es el dueño original del Lead,
            //    Y la última orden (si existe) NO le pertenece a otro agente.
            // Esto cubre:
            // 1. Leads puros (sin órdenes)
            // 2. Clientes con órdenes donde la última orden NO tiene un vendedor asignado aún (agent_id IS NULL)
            ->orWhere(function ($sub) use ($userId) {
                $sub->where('agent_id', $userId)
                    ->whereDoesntHave('orders', function ($oq) use ($userId) {
                        $oq->whereNotNull('agent_id')
                           ->where('agent_id', '!=', $userId)
                           ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
                    });
            });
        });
    }

    /**
     * Verifica si el agente tiene acceso a un cliente específico.
     * Retorna true si tiene acceso, false si no.
     */
    private function hasAccessToClient(int $clientId, $user): bool
    {
        if ($this->isAdmin()) {
            return Client::where('id', $clientId)->exists();
        }

        $query = Client::where('id', $clientId);
        $this->applyVisibilityScope($query, $user);
        
        return $query->exists();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /whatsapp-crm/conversations
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * Lista de conversaciones para la sidebar del CRM v2.
     * El servidor filtra por permisos — el frontend recibe SOLO lo que le corresponde.
     */
    public function index(Request $request)
    {
        $user     = Auth::user();
        $isAdmin  = $this->isAdmin();

        $search    = $request->query('search');
        $bucket    = $request->query('bucket', 'all');
        $agentId   = $request->query('agent_id');
        $startDate = $request->query('start_date');
        $endDate   = $request->query('end_date');

        $query = Client::query();

        // 1. Solo clientes con al menos un mensaje de WhatsApp
        $query->whereHas('whatsappMessages');

        // 2. Visibilidad — el núcleo del nuevo sistema
        if (!$isAdmin) {
            $this->applyVisibilityScope($query, $user);
        } else {
            // Admin puede filtrar por agente específico
            if ($agentId) {
                $this->applyVisibilityScope($query, $agentId);
            }
        }

        // 3. Búsqueda por nombre, teléfono o número de orden
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhereHas('orders', function ($oq) use ($search) {
                      $oq->where('name', 'like', "%{$search}%")
                         ->orWhere('order_number', 'like', "%{$search}%");
                  });
            });
        }

        // 4. Filtro por bucket
        if ($bucket && $bucket !== 'all') {
            if ($bucket === 'requires_attention') {
                // Atención = tiene mensajes no leídos O la DB dice que requiere atención
                $query->where(function($q) {
                    $q->whereHas('whatsappMessages', function ($mq) {
                        $mq->where('is_from_client', true)->where('status', '!=', 'read');
                    })
                    ->orWhereHas('whatsappConversations', function ($cq) {
                        $cq->where('conversation_bucket', 'requires_attention')
                           ->where('status', 'open');
                    });
                });
            } elseif ($bucket === 'closed') {
                $query->whereHas('whatsappConversations', function ($cq) {
                    $cq->where('conversation_bucket', 'closed')
                       ->where('status', 'open');
                });
            } elseif ($bucket === 'follow_up') {
                // Seguimiento = NO tiene no-leídos Y (DB dice seguimiento O no tiene registro de conversación)
                $query->whereDoesntHave('whatsappMessages', function ($mq) {
                    $mq->where('is_from_client', true)->where('status', '!=', 'read');
                })
                ->where(function($q) {
                    $q->whereHas('whatsappConversations', function ($cq) {
                        $cq->where('conversation_bucket', 'follow_up')
                           ->where('status', 'open');
                    })
                    ->orWhereDoesntHave('whatsappConversations');
                });
            }
        }

        // 5. Filtro por rango de fecha
        if ($startDate) {
            $query->where('last_interaction_at', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('last_interaction_at', '<=', $endDate . ' 23:59:59');
        }

        // 6. Contadores de mensajes no leídos
        $query->withCount(['whatsappMessages as unread_count' => function ($q) {
            $q->where('is_from_client', true)->where('status', '!=', 'read');
        }]);
        
        $sortBy = $request->query('sort_by', 'latency');

        // 7. Orden dinámico
        if ($sortBy === 'unread') {
            $query->orderBy('unread_count', 'desc');
        }
        
        $query->orderByRaw('COALESCE(last_interaction_at, last_whatsapp_received_at, created_at) DESC');

        // 8. Eager load relaciones necesarias
        $paginator = $query
            ->with([
                'activeWhatsappConversation', // Usar la relación activa que creamos
                'latestWhatsappMessage',
                'latestOrder.status',
                'latestOrder.agent',
                'latestOrder.products.product',
                'latestOrder.city',
                'latestOrder.province',
                'agent',
            ])
            ->paginate(50);

        // 9. Transformar la respuesta
        $bucketCounts = ['requires_attention' => 0, 'follow_up' => 0, 'closed' => 0];

        $paginator->getCollection()->transform(function ($client) use (&$bucketCounts) {
            $conversation = $client->activeWhatsappConversation;
            $latestMsg    = $client->latestWhatsappMessage;
            $order        = $client->latestOrder;

            // Lógica CENTRALIZADA Y ESTRICTA
            $isTerminalOrder = $order && $order->status && in_array($order->status->description, ['Entregado', 'Cancelado', 'Rechazado']);
            $hasUnread = ($client->unread_count ?? 0) > 0;
            $lastIsClient = $latestMsg && $latestMsg->is_from_client;

            if ($hasUnread || $lastIsClient) {
                $bucketName = 'requires_attention';
            } elseif ($isTerminalOrder) {
                $bucketName = 'closed';
            } else {
                $bucketName = $conversation?->conversation_bucket ?? 'follow_up';
                if ($bucketName === 'requires_attention' && $latestMsg && !$latestMsg->is_from_client) {
                    $bucketName = 'follow_up';
                }
            }

            if (isset($bucketCounts[$bucketName])) $bucketCounts[$bucketName]++;

            $productsTitle = '';
            if ($order && $order->products) {
                $productsTitle = $order->products->map(function ($p) {
                    return ($p->product->name ?? 'Producto') . " (x{$p->quantity})";
                })->implode(', ');
            }

            return [
                'client_id'           => $client->id,
                'client_name'         => trim($client->first_name . ' ' . $client->last_name),
                'client_phone'        => $client->phone,
                'agent_id'            => $client->agent_id,
                'agent_name'          => $client->agent?->names,
                'is_lead'             => !$order,
                'is_window_open'      => $client->isWhatsappWindowOpen(),
                'unread_count'        => $client->unread_count,
                'last_message'        => $latestMsg?->body ?? 'Sin mensajes',
                'last_message_at'     => $latestMsg?->sent_at ?? $client->created_at,
                'last_message_type'   => $latestMsg && $latestMsg->is_from_client ? 'incoming_message' : 'outgoing_agent_message',
                'conversation_bucket' => $bucketName,
                'conversation_id'     => $conversation?->id,
                'order'               => $order ? [
                    'id'              => $order->id,
                    'order_number'    => $order->order_number ?? $order->name,
                    'status'          => $order->status?->description,
                    'status_id'       => $order->status_id,
                    'products_summary'=> $productsTitle,
                    'total_usd'       => $order->current_total_price,
                    'total_ves'       => $order->ves_price,
                    'bcv_equivalence' => $order->bcv_equivalence,
                    'agent_name'      => $order->agent?->names,
                    'created_at'      => $order->created_at,
                    'reset_count'     => $order->reset_count ?? 0,
                    'location'        => $order->city?->name ?? '-',
                ] : null,
            ];
        });

        // ── Contadores de buckets GLOBALES (para los badges del header) ─────────────────
        $statsQuery = Client::whereHas('whatsappMessages');
        if (!$isAdmin) { $this->applyVisibilityScope($statsQuery, $user); }
        elseif ($agentId) { $this->applyVisibilityScope($statsQuery, $agentId); }

        $allStats = $statsQuery->with(['activeWhatsappConversation', 'latestWhatsappMessage', 'latestOrder.status'])
            ->withCount(['whatsappMessages as unread_count' => function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            }])
            ->get(['id', 'agent_id']);
        $totalCounts = ['requires_attention' => 0, 'follow_up' => 0, 'closed' => 0];

        foreach ($allStats as $s) {
            $latest = $s->latestWhatsappMessage;
            $order  = $s->latestOrder;
            
            $isTerminal = $order && $order->status && in_array($order->status->description, ['Entregado', 'Cancelado', 'Rechazado']);
            $hasUnread = ($s->unread_count ?? 0) > 0;
            $lastIsClient = $latest && $latest->is_from_client;

            if ($hasUnread || $lastIsClient) {
                $b = 'requires_attention';
            } elseif ($isTerminal) {
                $b = 'closed';
            } else {
                $b = $s->activeWhatsappConversation?->conversation_bucket ?? 'follow_up';
                if ($b === 'requires_attention' && $latest && !$latest->is_from_client) {
                    $b = 'follow_up';
                }
            }
            if (isset($totalCounts[$b])) $totalCounts[$b]++;
        }

        return response()->json(array_merge($paginator->toArray(), [
            'bucket_counts' => $totalCounts,
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /whatsapp-crm/conversations/{client}/messages
    // ─────────────────────────────────────────────────────────────────────────
    public function show(int $clientId)
    {
        $user = Auth::user();

        if (!$this->hasAccessToClient($clientId, $user)) {
            return response()->json(['message' => 'Acceso denegado a este chat.'], 403);
        }

        $messages = WhatsappMessage::where('client_id', $clientId)
            ->orderBy('sent_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /whatsapp-crm/conversations/{client}/messages
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request, int $clientId)
    {
        $user = Auth::user();

        if (!$this->hasAccessToClient($clientId, $user)) {
            return response()->json(['message' => 'No tienes permiso para enviar mensajes a este cliente.'], 403);
        }

        $client = Client::findOrFail($clientId);

        // Validar ventana de 24h (solo para mensajes libres, no plantillas)
        if (!$request->filled('template_name')) {
            if (!$client->isWhatsappWindowOpen()) {
                return response()->json(['message' => 'Ventana de 24 horas cerrada. Usa una plantilla oficial.'], 403);
            }
        }

        $latestOrder = Order::where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $renderedBody = $request->body;
        $components   = [];
        $vars         = $request->vars ?? [];
        $tpl          = null;
        $service      = new \App\Services\WhatsAppService();

        if ($request->filled('template_name')) {
            $tpl = \App\Models\WhatsappTemplate::where('name', $request->template_name)->first();
            if ($tpl) {
                $renderedBody = $tpl->render($vars);
            }
        }

        $message = WhatsappMessage::create([
            'order_id'       => $latestOrder?->id,
            'client_id'      => $client->id,
            'body'           => $renderedBody,
            'is_from_client' => false,
            'message_type'   => WhatsappMessage::TYPE_AGENT,
            'status'         => 'sending',
            'sent_at'        => now(),
        ]);

        // Enviar via API de WhatsApp
        if ($request->filled('template_name')) {
            if ($tpl && !empty($tpl->meta_components)) {
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
                    }

                    if (!empty($parameters)) {
                        $components[] = ['type' => strtolower($rawType), 'parameters' => $parameters];
                    }
                }
            } elseif ($request->has('vars')) {
                $parameters  = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $request->vars);
                $components[] = ['type' => 'body', 'parameters' => $parameters];
            }

            $result = $service->sendTemplate($client->phone, $request->template_name, 'es', $components);
        } else {
            $result = $service->sendMessage($client->phone, $message->body);
        }

        if ($result && isset($result['messages'][0]['id'])) {
            $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
        } else {
            $message->update(['status' => 'failed']);
            Log::error('WHATSAPP_CRM_SEND_ERROR: ' . json_encode($result));
        }

        // Marcar como leídos todos los mensajes del cliente al responder
        WhatsappMessage::where('client_id', $client->id)
            ->where('is_from_client', true)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        $client->update(['last_interaction_at' => now()]);
        $message->refresh();

        ConversationBucketService::recalculate($client->id);
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /whatsapp-crm/conversations/{client}/media
    // ─────────────────────────────────────────────────────────────────────────
    public function sendMedia(Request $request, int $clientId)
    {
        $request->validate(['file' => 'required|file|max:16384']);

        $user = Auth::user();

        if (!$this->hasAccessToClient($clientId, $user)) {
            return response()->json(['message' => 'No tienes permiso para enviar archivos a este cliente.'], 403);
        }

        $client  = Client::findOrFail($clientId);
        $file    = $request->file('file');
        $path    = $file->store('whatsapp_media', 'public');
        $fullPath = storage_path('app/public/' . $path);

        $service = new \App\Services\WhatsAppService();
        $mime    = $file->getMimeType();
        $type    = str_starts_with($mime, 'image/') ? 'image'
                : (str_starts_with($mime, 'video/') ? 'video' : 'document');

        $upload = $service->uploadMedia($fullPath, $type);

        if ($upload && isset($upload['id'])) {
            $send = $service->sendMedia($client->phone, $upload['id'], $type, $request->caption);
            if ($send && isset($send['messages'][0]['id'])) {
                $latestOrder = Order::where('client_id', $client->id)->orderBy('created_at', 'desc')->first();

                $msg = WhatsappMessage::create([
                    'order_id'       => $latestOrder?->id,
                    'client_id'      => $client->id,
                    'message_id'     => $send['messages'][0]['id'],
                    'body'           => $request->caption ?? "Archivo {$type}",
                    'is_from_client' => false,
                    'message_type'   => WhatsappMessage::TYPE_AGENT,
                    'status'         => 'sent',
                    'sent_at'        => now(),
                    'media'          => asset('storage/' . $path),
                ]);

                // Marcar como leídos todos los mensajes del cliente al responder
                WhatsappMessage::where('client_id', $client->id)
                    ->where('is_from_client', true)
                    ->where('status', '!=', 'read')
                    ->update(['status' => 'read']);

                ConversationBucketService::recalculate($client->id);
                event(new \App\Events\WhatsappMessageReceived($msg));

                return response()->json($msg, 201);
            }
        }

        return response()->json(['message' => 'Error al enviar el archivo.'], 500);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /whatsapp-crm/conversations/{client}/read
    // ─────────────────────────────────────────────────────────────────────────
    public function markAsRead(int $clientId)
    {
        $user = Auth::user();

        if (!$this->hasAccessToClient($clientId, $user)) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $client = Client::findOrFail($clientId);

        WhatsappMessage::where('client_id', $clientId)
            ->where('is_from_client', true)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        // Recalcular bucket: al leer, si la vendedora ya respondió antes → follow_up
        // Si la orden está Entregada/Cancelada → closed
        $newBucket = ConversationBucketService::recalculate($clientId);

        event(new \App\Events\WhatsappChatRead((int)$clientId, $client->agent_id));

        return response()->json([
            'status' => true, 
            'message' => 'Mensajes marcados como leídos',
            'conversation_bucket' => $newBucket
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /whatsapp-crm/conversations/{client}/move
    // ─────────────────────────────────────────────────────────────────────────
    public function moveToBucket(Request $request, int $clientId)
    {
        $request->validate(['bucket' => 'required|in:requires_attention,follow_up,closed']);

        $user = Auth::user();

        if (!$this->hasAccessToClient($clientId, $user)) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $conv = WhatsappConversation::firstOrCreate(
            ['client_id' => $clientId, 'status' => 'open'],
            ['conversation_bucket' => $request->bucket]
        );

        $conv->update([
            'conversation_bucket' => $request->bucket,
            'is_manual_bucket'    => true,
        ]);

        return response()->json([
            'status'       => true,
            'message'      => 'Chat movido a ' . $request->bucket,
            'conversation' => $conv,
        ]);
    }

    /**
     * Reasignar una conversación específica (y opcionalmente el cliente/orden).
     */
    public function assignAgent(Request $request, $clientId)
    {
        if (!$this->isAdmin()) {
            return response()->json(['message' => 'No tienes permiso para reasignar chats.'], 403);
        }

        $request->validate(['agent_id' => 'required|exists:users,id']);
        
        $conv = WhatsappConversation::firstOrCreate(
            ['client_id' => $clientId, 'status' => 'open'],
            ['agent_id' => $request->agent_id]
        );

        $conv->update(['agent_id' => $request->agent_id]);

        // Sync client
        $client = $conv->client;
        if ($client) {
            $client->update(['agent_id' => $request->agent_id]);
            
            // Sync latest active order
            $latestOrder = Order::where('client_id', $client->id)->orderBy('created_at', 'desc')->first();
            if ($latestOrder) {
                $latestOrder->load('status');
                $terminalStatuses = ['Entregado', 'Cancelado', 'Rechazado'];
                if (!$latestOrder->status || !in_array($latestOrder->status->description, $terminalStatuses)) {
                    $latestOrder->update(['agent_id' => $request->agent_id]);
                    event(new \App\Events\OrderUpdated($latestOrder));
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Chat reasignado exitosamente',
            'conversation' => $conv->load('agent')
        ]);
    }
}
