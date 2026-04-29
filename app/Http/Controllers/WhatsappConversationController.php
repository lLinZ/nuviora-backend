<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\ConversationBucketService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Constants\OrderStatus;

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
        
        $roleName = $user->role ? strtolower(trim($user->role->description)) : '';
        $superRoles = ['admin', 'manager', 'gerente', 'master'];
        $isAdmin  = in_array($roleName, $superRoles);
        $isAgent  = str_contains($roleName, 'vende');

        $search   = $request->query('search');
        $agentId  = $request->query('agent_id');
        $startDate = $request->query('start_date');
        $endDate   = $request->query('end_date');
        $sortBy    = $request->query('sort_by', 'latency'); // 'latency' o 'messages_count'

        $query = Client::query()->select('clients.*')->distinct();

        // 1. Filtrar por búsqueda
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('id', $search)
                  ->orWhereHas('orders', function ($oq) use ($search) {
                      $oq->where('name', 'like', "%{$search}%")
                         ->orWhere('id', 'like', "%{$search}%");
                  });
            });
        }

        // 2. Filtrar por bucket (nuevo sistema)
        $bucket = $request->query('bucket', 'all');
        if ($bucket && $bucket !== 'all') {
                $query->whereHas('whatsappConversations', function ($cq) {
                    $cq->where('conversation_bucket', 'requires_attention')
                       ->where('status', 'open');
                });
            } else {
                $query->whereHas('whatsappConversations', function ($cq) use ($bucket) {
                    $cq->where('conversation_bucket', $bucket)
                       ->where('status', 'open');
                });
            }
        } else {
            // 'all': mostrar todos los clientes que tienen al menos un mensaje de WhatsApp
            $query->whereHas('whatsappMessages');
        }

        // 3. Filtro por Vendedora (agent_id) - Solo admin puede forzar este filtro para ver a otros
        if ($isAdmin && $agentId) {
            $query->where('agent_id', $agentId);
        }

        // 4. Filtro por Rango de Fecha (last_interaction_at o last_message_at de la conversación)
        if ($startDate) {
            $query->whereHas('whatsappConversations', function($cq) use ($startDate) {
                $cq->where('last_message_at', '>=', $startDate . ' 00:00:00');
            });
        }
        if ($endDate) {
            $query->whereHas('whatsappConversations', function($cq) use ($endDate) {
                $cq->where('last_message_at', '<=', $endDate . ' 23:59:59');
            });
        }

        // 5. Filtrar visibilidad según rol (si es vendedora o no es admin)
        if (!$isAdmin || $isAgent) {
            $query->where(function ($q) use ($user) {
                // A. Eres el dueño de la ÚLTIMA orden (campo agent_id en orders)
                $q->whereHas('latestOrder', function($oq) use ($user) {
                    $oq->where('agent_id', $user->id);
                })
                // B. O eres el dueño del cliente (campo agent_id en clients)
                // Pero si hay una orden reciente de otra persona, el chat le pertenece a ella
                ->orWhere(function ($sub) use ($user) {
                    $sub->where('agent_id', $user->id)
                        ->whereDoesntHave('latestOrder', function($lo) use ($user) {
                            $lo->whereNotNull('agent_id')->where('agent_id', '!=', $user->id);
                        });
                })
                // C. O tienes la conversación abierta a tu nombre específicamente
                ->orWhereHas('whatsappConversations', function($cq) use ($user) {
                    $cq->where('agent_id', $user->id)->where('status', 'open');
                });
            });
        }

        // 6. Ordenar
        $query->withCount(['whatsappMessages as unread_count' => function ($q) {
            $q->where('is_from_client', true)->where('status', '!=', 'read');
        }]);

        if ($sortBy === 'messages_count') {
            // Ordenar por cantidad de mensajes SIN LEER (Urgencia)
            $query->orderBy('unread_count', 'desc');
        } else {
            // Ordenar por orden de llegada (último mensaje)
            $query->orderByRaw('COALESCE(last_interaction_at, last_whatsapp_received_at, created_at) DESC');
        }

        $paginator = $query
            ->with(['agent', 'latestOrder.agent', 'latestOrder.status', 'latestOrder.products.product', 'activeWhatsappConversation'])
            ->with('latestWhatsappMessage')
            ->paginate(50);

        $paginator->getCollection()->transform(function ($client) {
            $latestMessage  = $client->latestWhatsappMessage;
            $conversation   = $client->latestWhatsappConversation;
            
            // Si tiene mensajes sin leer, forzar bucket 'requires_attention' en la UI (Sincronización total)
            $bucket = $client->activeWhatsappConversation?->conversation_bucket ?? 'follow_up';
            
            $order = $client->latestOrder;
            $productsTitle = "";
            if ($order && $order->products) {
                $productsTitle = $order->products->map(function($p) {
                    return ($p->product->name ?? 'Producto') . " (x" . $p->quantity . ")";
                })->implode(', ');
            }

            return [
                'id'                  => $client->id,
                'name'                => $client->first_name . ' ' . $client->last_name,
                'phone'               => $client->phone,
                'unread_count'        => $client->unread_count,
                'is_window_open'      => $client->isWhatsappWindowOpen(),
                'last_message'        => $latestMessage ? $latestMessage->body : 'Sin mensajes',
                'last_message_date'   => $latestMessage ? $latestMessage->sent_at : $client->created_at,
                'last_message_type'   => $latestMessage?->message_type ?? 'outgoing_agent_message',
                'conversation_bucket' => $bucket,
                'products_summary'    => $productsTitle,
                'total_ves'           => $order ? $order->ves_price : 0,
                'context'             => [
                    'order'        => $order,
                    'agent'        => $client->agent,
                    'conversation' => $conversation,
                ]
            ];
        });

        // ── Contadores globales por bucket (para los badges del sidebar) ──
        // El query de base ya tiene los filtros de búsqueda, fechas, vendedora y visibilidad aplicados.
        $statsQuery = Client::query();
        if ($search) {
            $statsQuery->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('id', $search)
                  ->orWhereHas('orders', function ($oq) use ($search) {
                      $oq->where('name', 'like', "%{$search}%")
                         ->orWhere('id', 'like', "%{$search}%");
                  });
            });
        }

        if (!$isAdmin || $isAgent) {
             $statsQuery->where(function ($q) use ($user) {
                $q->whereHas('latestOrder', function($oq) use ($user) {
                    $oq->where('agent_id', $user->id);
                })
                ->orWhere(function ($sub) use ($user) {
                    $sub->where('agent_id', $user->id)
                        ->whereDoesntHave('latestOrder', function($lo) use ($user) {
                            $lo->whereNotNull('agent_id')->where('agent_id', '!=', $user->id);
                        });
                })
                ->orWhereHas('whatsappConversations', function($cq) use ($user) {
                    $cq->where('agent_id', $user->id)->where('status', 'open');
                });
            });
        }
        
        if ($isAdmin && $agentId) {
            $statsQuery->where('agent_id', $agentId);
        }

        // Obtener buckets de todos los clientes visibles
        $visibleClients = $statsQuery->with('activeWhatsappConversation')
            ->withCount(['whatsappMessages as has_unread' => function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            }])
            ->get(['id', 'agent_id']);

        $bucketCounts = [
            'requires_attention' => 0,
            'follow_up'          => 0,
            'closed'             => 0,
        ];

        foreach ($visibleClients as $vc) {
            $b = $vc->activeWhatsappConversation?->conversation_bucket ?? 'follow_up';
            if (isset($bucketCounts[$b])) $bucketCounts[$b]++;
        }

        $criticalThreshold = now()->subMinutes(30);
        $criticalCount = $statsQuery->whereHas('whatsappMessages', function($mq) use ($criticalThreshold) {
            $mq->where('is_from_client', true)
               ->where('status', '!=', 'read')
               ->where('sent_at', '<', $criticalThreshold);
        })->count();

        return response()->json(array_merge($paginator->toArray(), [
            'bucket_counts'  => $bucketCounts,
            'critical_count' => $criticalCount,
        ]));
    }

    /**
     * Trae todos los mensajes de un cliente con verificación de privacidad.
     */
    public function show($clientId)
    {
        $user = Auth::user();
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }
        $roleName = $user->role ? strtolower(trim($user->role->description)) : '';
        $isAdmin = in_array($roleName, ['admin', 'manager', 'gerente', 'master']);
        $isAgent = str_contains($roleName, 'vende');

        $query = Client::where('id', $clientId);

        if (!$isAdmin || $isAgent) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('latestOrder', function($oq) use ($user) {
                    $oq->where('agent_id', $user->id);
                })
                ->orWhere(function ($sub) use ($user) {
                    $sub->where('agent_id', $user->id)
                        ->whereDoesntHave('latestOrder', function($lo) use ($user) {
                            $lo->whereNotNull('agent_id')->where('agent_id', '!=', $user->id);
                        });
                })
                ->orWhereHas('whatsappConversations', function($cq) use ($user) {
                    $cq->where('agent_id', $user->id)->where('status', 'open');
                });
            });
        }

        $client = $query->first();

        if (!$client) {
            return response()->json(['message' => 'No tienes acceso a este chat o el cliente no existe.'], 403);
        }

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
        $user = Auth::user();
        if (!$user->relationLoaded('role')) $user->load('role');
        
        $roleName = $user->role ? strtolower(trim($user->role->description)) : '';
        $isAdmin = in_array($roleName, ['admin', 'manager', 'gerente', 'master']);
        $isAgent = str_contains($roleName, 'vende');

        $client = Client::findOrFail($clientId);

        // PRIVACY CHECK — misma logica que show()
        if (!$isAdmin || $isAgent) {
            $hasAccess = Client::where('id', $clientId)
                ->where(function ($q) use ($user) {
                    $q->whereHas('latestOrder', function($oq) use ($user) {
                        $oq->where('agent_id', $user->id);
                    })
                    ->orWhere(function ($sub) use ($user) {
                        $sub->where('agent_id', $user->id)
                            ->whereDoesntHave('latestOrder', function($lo) use ($user) {
                                $lo->whereNotNull('agent_id')->where('agent_id', '!=', $user->id);
                            });
                    })
                    ->orWhereHas('whatsappConversations', function($cq) use ($user) {
                        $cq->where('agent_id', $user->id)->where('status', 'open');
                    });
                })->exists();

            if (!$hasAccess) {
                return response()->json(['message' => 'No tienes permiso para enviar mensajes a este cliente.'], 403);
            }
        }
        
        if (!$request->input('is_from_client', false) && !$request->filled('template_name')) {
            if (!$client->isWhatsappWindowOpen()) {
                return response()->json(['message' => 'Ventana de 24 horas cerrada'], 403);
            }
        }

        $latestOrder = \App\Models\Order::where('client_id', $client->id)->orderBy('created_at', 'desc')->first();

        $renderedBody = $request->body;
        $components = [];
        $vars = $request->vars ?? [];
        $tpl = null;
        $service = new \App\Services\WhatsAppService();

        if ($request->filled('template_name')) {
            $tpl = \App\Models\WhatsappTemplate::where('name', $request->template_name)->first();
            if ($tpl) {
                $renderedBody = $tpl->render($vars);
                Log::critical("DEBUG_WA: Enviando plantilla estructurada " . $request->template_name);
            }
        }

        $messageBody = $renderedBody ?? $request->body;

        $message = WhatsappMessage::create([
            'order_id'     => $latestOrder ? $latestOrder->id : null,
            'client_id'    => $client->id,
            'body'         => $messageBody,
            'is_from_client' => $request->input('is_from_client', false),
            'message_type' => $request->input('is_from_client', false)
                ? WhatsappMessage::TYPE_INCOMING
                : WhatsappMessage::TYPE_AGENT,
            'status'       => 'sending',
            'sent_at'      => now(),
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
        
        // Al responder, marcar automáticamente todo lo anterior como leído
        WhatsappMessage::where('client_id', $client->id)
            ->where('is_from_client', true)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        $message->refresh();

        // Recalculate bucket — agent reply moves conversation to follow_up
        $newBucket = ConversationBucketService::recalculate($client->id);
        
        // Inyectar el bucket actualizado en el mensaje para que el WebSocket lo lleve al frontend
        $message->conversation_bucket = $newBucket;

        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    /**
     * Enviar multimedia
     */
    public function sendMedia(Request $request, $clientId)
    {
        $request->validate(['file' => 'required|file|max:16384']);
        
        $user = Auth::user();
        if (!$user->relationLoaded('role')) $user->load('role');

        $roleName = $user->role ? strtolower(trim($user->role->description)) : '';
        $isAdmin = in_array($roleName, ['admin', 'manager', 'gerente', 'master']);
        $isAgent = str_contains($roleName, 'vende');

        $client = Client::findOrFail($clientId);

        // PRIVACY CHECK
        if (!$isAdmin || $isAgent) {
            $hasAccess = Client::where('id', $clientId)
                ->where(function ($q) use ($user) {
                    $q->whereHas('latestOrder', function($oq) use ($user) {
                        $oq->where('agent_id', $user->id);
                    })
                    ->orWhere(function ($sub) use ($user) {
                        $sub->where('agent_id', $user->id)
                            ->whereDoesntHave('latestOrder', function($lo) use ($user) {
                                $lo->whereNotNull('agent_id')->where('agent_id', '!=', $user->id);
                            });
                    })
                    ->orWhereHas('whatsappConversations', function($cq) use ($user) {
                        $cq->where('agent_id', $user->id)->where('status', 'open');
                    });
                })->exists();

            if (!$hasAccess) {
                return response()->json(['message' => 'No tienes permiso para enviar multimedia a este cliente.'], 403);
            }
        }
        
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
                    'client_id'      => $client->id,
                    'message_id'     => $send['messages'][0]['id'],
                    'body'           => $request->caption ?? "Archivo {$type}",
                    'is_from_client' => false,
                    'message_type'   => WhatsappMessage::TYPE_AGENT,
                    'status'         => 'sent',
                    'sent_at'        => now(),
                    'media'          => asset('storage/' . $path)
                ]);

                // Marcar como leído al enviar multimedia también
                WhatsappMessage::where('client_id', $client->id)
                    ->where('is_from_client', true)
                    ->where('status', '!=', 'read')
                    ->update(['status' => 'read']);

                $newBucket = ConversationBucketService::recalculate($client->id);
                $msg->conversation_bucket = $newBucket;

                event(new \App\Events\WhatsappMessageReceived($msg));
                return response()->json($msg, 201);
            }
        }
        return response()->json(['message' => 'Error'], 500);
    }

    /**
     * Reasignar una conversación específica (y opcionalmente el cliente/orden).
     */
    public function assignAgent(Request $request, $conversationId)
    {
        $request->validate(['agent_id' => 'required|exists:users,id']);
        
        $conv = WhatsappConversation::findOrFail($conversationId);
        $conv->update(['agent_id' => $request->agent_id]);

        // Sync client
        $client = $conv->client;
        if ($client) {
            $client->update(['agent_id' => $request->agent_id]);
            
            // Sync latest active order
            $latestOrder = $client->latestOrder;
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

    /**
     * Marca todos los mensajes entrantes de un cliente como leídos.
     */
    public function markAsRead(Request $request, $clientId)
    {
        $user = Auth::user();
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }

        $roleName = $user->role ? strtolower(trim($user->role->description)) : '';
        $isAdmin = in_array($roleName, ['admin', 'manager', 'gerente', 'master']);
        $isAgent = str_contains($roleName, 'vende');

        // PRIVACY CHECK (same as index/show)
        $query = Client::where('id', $clientId);
        if (!$isAdmin || $isAgent) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('latestOrder', function($oq) use ($user) {
                    $oq->where('agent_id', $user->id);
                })
                ->orWhere(function ($sub) use ($user) {
                    $sub->where('agent_id', $user->id)
                        ->whereDoesntHave('latestOrder', function($lo) use ($user) {
                            $lo->whereNotNull('agent_id')->where('agent_id', '!=', $user->id);
                        });
                })
                ->orWhereHas('whatsappConversations', function($cq) use ($user) {
                    $cq->where('agent_id', $user->id)->where('status', 'open');
                });
            });
        }

        $client = $query->first();

        if (!$client) {
            return response()->json(['message' => 'No tienes acceso a este chat.'], 403);
        }

        // Actualizar estados
        \App\Models\WhatsappMessage::where('client_id', $clientId)
            ->where('is_from_client', true)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        // Broadcast real-time event so other tabs/sessions update the unread count immediately
        event(new \App\Events\WhatsappChatRead(
            (int) $clientId,
            $client->agent_id
        ));

        return response()->json(['status' => true, 'message' => 'Mensajes marcados como leídos']);
    }

    /**
     * Mueve un chat a un bucket específico manualmente.
     */
    public function moveToBucket(Request $request, $clientId)
    {
        $request->validate(['bucket' => 'required|in:requires_attention,follow_up,closed']);
        
        $conv = WhatsappConversation::firstOrCreate(
            ['client_id' => $clientId, 'status' => 'open'],
            ['conversation_bucket' => $request->bucket]
        );

        $conv->update([
            'conversation_bucket' => $request->bucket,
            'is_manual_bucket'     => true
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Chat movido a ' . $request->bucket,
            'conversation' => $conv
        ]);
    }
}
