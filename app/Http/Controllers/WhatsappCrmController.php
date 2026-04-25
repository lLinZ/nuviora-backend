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
    private function applyVisibilityScope($query, $user): void
    {
        // ── REGLA SIMPLE ────────────────────────────────────────────────────
        // A) Tiene órdenes → la última orden debe ser de este agente
        // B) No tiene órdenes (lead) → el cliente.agent_id debe ser este agente
        $query->where(function ($q) use ($user) {
            // A. Clientes con órdenes: la orden más reciente pertenece al agente
            $q->whereHas('orders', function ($oq) use ($user) {
                $oq->where('agent_id', $user->id)
                   ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
            })
            // B. Leads sin órdenes asignados directamente al agente
            ->orWhere(function ($sub) use ($user) {
                $sub->where('agent_id', $user->id)
                    ->doesntHave('orders');
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

        return Client::where('id', $clientId)
            ->where(function ($q) use ($user) {
                // A. La orden más reciente es del agente
                $q->whereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
                })
                // B. Lead sin órdenes asignado al agente
                ->orWhere(function ($sub) use ($user) {
                    $sub->where('agent_id', $user->id)
                        ->doesntHave('orders');
                });
            })
            ->exists();
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
                // Ver clientes cuya última orden pertenece a ese agente
                $query->where(function ($q) use ($agentId) {
                    $q->whereHas('orders', function ($oq) use ($agentId) {
                        $oq->where('agent_id', $agentId)
                           ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
                    })
                    ->orWhere(function ($sub) use ($agentId) {
                        $sub->where('agent_id', $agentId)->doesntHave('orders');
                    });
                });
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
                // Atención = tiene no-leídos OR (conversation_bucket = requires_attention AND NO tiene orden terminal)
                $query->where(function ($q) {
                    $q->whereHas('whatsappMessages', function ($mq) {
                        $mq->where('is_from_client', true)->where('status', '!=', 'read');
                    })
                    ->orWhere(function ($sub) {
                        $sub->whereHas('whatsappConversations', function ($cq) {
                            $cq->where('conversation_bucket', 'requires_attention');
                        })
                        ->whereDoesntHave('orders', function ($oq) {
                            $oq->whereHas('status', function ($sq) {
                                $sq->whereIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                            })
                            ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
                        });
                    });
                });
            } elseif ($bucket === 'closed') {
                // Cerrado = (NO tiene no-leídos) AND (orden Terminal OR conversation_bucket = closed)
                $query->whereDoesntHave('whatsappMessages', function ($mq) {
                    $mq->where('is_from_client', true)->where('status', '!=', 'read');
                })
                ->where(function ($q) {
                    $q->whereHas('orders', function ($oq) {
                        $oq->whereHas('status', function ($sq) {
                            $sq->whereIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                        })
                        ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
                    })
                    ->orWhereHas('whatsappConversations', function ($cq) {
                        $cq->where('conversation_bucket', 'closed');
                    });
                });
            } elseif ($bucket === 'follow_up') {
                // Seguimiento = (NO tiene no-leídos) AND (NO tiene orden Terminal) AND (conversation_bucket = follow_up OR no tiene conversacion)
                $query->whereDoesntHave('whatsappMessages', function ($mq) {
                    $mq->where('is_from_client', true)->where('status', '!=', 'read');
                })
                ->whereDoesntHave('orders', function ($oq) {
                    $oq->whereHas('status', function ($sq) {
                        $sq->whereIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                    })
                    ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
                })
                ->where(function ($q) {
                    $q->whereHas('whatsappConversations', function ($cq) {
                        $cq->where('conversation_bucket', 'follow_up');
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

        // 7. Orden: primero los con no leídos, luego por interacción más reciente
        $query->orderByRaw('unread_count DESC')
              ->orderByRaw('COALESCE(last_interaction_at, last_whatsapp_received_at, created_at) DESC');

        // 8. Eager load relaciones necesarias
        $paginator = $query
            ->with([
                'latestWhatsappConversation',
                'latestWhatsappMessage',
                'latestOrder.status',
                'latestOrder.agent',
                'latestOrder.products.product',
                'agent',
            ])
            ->paginate(50);

        // 9. Transformar la respuesta
        $paginator->getCollection()->transform(function ($client) {
            $conversation = $client->latestWhatsappConversation;
            $latestMsg    = $client->latestWhatsappMessage;
            $order        = $client->latestOrder;

            // ── Calcular bucket con la misma lógica del ConversationBucketService ──
            // PRIORIDAD:
            //  1. Mensajes no leídos del cliente  → requires_attention
            //  2. Orden Entregada/Cancelada/Rechazada → closed (si no hay no-leídos)
            //  3. Lo que diga la conversación en DB (follow_up por defecto)
            $terminalStatuses = ['Entregado', 'Cancelado', 'Rechazado'];
            $orderStatus = $order?->status?->description;
            $isTerminal  = $orderStatus && in_array($orderStatus, $terminalStatuses);

            if ($client->unread_count > 0) {
                $bucketName = 'requires_attention';
            } elseif ($isTerminal) {
                $bucketName = 'closed';
            } else {
                $bucketName = $conversation?->conversation_bucket ?? 'follow_up';
            }

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
                'last_message_type'   => $latestMsg?->message_type ?? 'outgoing_agent_message',
                'conversation_bucket' => $bucketName,
                'conversation_id'     => $conversation?->id,
                // Datos de la orden para el botón "Ver Orden"
                'order'               => $order ? [
                    'id'              => $order->id,
                    'order_number'    => $order->order_number,
                    'status'          => $order->status?->description,
                    'status_id'       => $order->status_id,
                    'products_summary'=> $productsTitle,
                    'total_usd'       => $order->current_total_price,
                    'total_ves'       => $order->ves_price,
                    'agent_name'      => $order->agent?->names,
                ] : null,
            ];
        });

        // ── Contadores de buckets para los badges del header ─────────────────
        // Usamos el mismo query base (con visibilidad) pero sin el filtro de bucket
        $statsBaseQuery = Client::whereHas('whatsappMessages');

        if (!$isAdmin) {
            $this->applyVisibilityScope($statsBaseQuery, $user);
        } elseif ($agentId) {
            $statsBaseQuery->where(function ($q) use ($agentId) {
                $q->whereHas('orders', function ($oq) use ($agentId) {
                    $oq->where('agent_id', $agentId)
                       ->whereRaw('id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.client_id = orders.client_id)');
                })
                ->orWhere(function ($sub) use ($agentId) {
                    $sub->where('agent_id', $agentId)->doesntHave('orders');
                });
            });
        }

        $allClients = $statsBaseQuery
            ->with(['latestWhatsappConversation', 'latestOrder.status'])
            ->withCount(['whatsappMessages as has_unread' => function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            }])
            ->get(['id']);

        $terminalStatuses = ['Entregado', 'Cancelado', 'Rechazado'];
        $bucketCounts = ['requires_attention' => 0, 'follow_up' => 0, 'closed' => 0];
        foreach ($allClients as $vc) {
            $orderStatus = $vc->latestOrder?->status?->description;
            $isTerminal  = $orderStatus && in_array($orderStatus, $terminalStatuses);

            if ($vc->has_unread > 0) {
                $b = 'requires_attention';
            } elseif ($isTerminal) {
                $b = 'closed';
            } else {
                $b = $vc->latestWhatsappConversation?->conversation_bucket ?? 'follow_up';
            }
            if (isset($bucketCounts[$b])) $bucketCounts[$b]++;
        }

        return response()->json(array_merge($paginator->toArray(), [
            'bucket_counts' => $bucketCounts,
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
        ConversationBucketService::recalculate($clientId);

        event(new \App\Events\WhatsappChatRead((int)$clientId, $client->agent_id));

        return response()->json(['status' => true, 'message' => 'Mensajes marcados como leídos']);
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
}
