<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
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

        // 2. Filtrar por estado de lectura (inteligente)
        $filter = $request->query('filter', 'all');
        if ($filter === 'unread') {
            $query->whereHas('whatsappMessages', function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            });
        } elseif ($filter === 'read') {
            $query->whereDoesntHave('whatsappMessages', function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            });
        }

        // 3. Filtrar visibilidad según el rol (Vendedoras solo ven lo suyo)
        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                // EXCLUSIVIDAD: Si hay un pedido activo/reciente (no Sin Stock), ese agente es el único dueño.
                // Prioridad 1: Eres el dueño del PEDIDO MÁS RECIENTE del cliente (y no es Sin Stock)
                $q->whereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereRaw('id = (SELECT id FROM orders o2 WHERE o2.client_id = orders.client_id ORDER BY created_at DESC LIMIT 1)')
                       ->whereHas('status', function($sq) {
                           $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                       });
                })
                // Prioridad 2: El cliente NO tiene pedidos válidos (distintos a Sin Stock),
                // en cuyo caso permitimos acceso si eres el Agente Asignado o tienes sesión abierta.
                ->orWhere(function($sub) use ($user) {
                    $sub->whereDoesntHave('orders', function($oq) {
                        $oq->whereHas('status', function($sq) {
                            $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                        });
                    })
                    ->where(function($inner) use ($user) {
                        $inner->where('agent_id', $user->id)
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
     * Trae todos los mensajes de un cliente con verificación de privacidad.
     */
    public function show($clientId)
    {
        $user = Auth::user();
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }
        $roleName = strtolower($user->role->description ?? '');
        $isAdmin = ($roleName === 'admin');

        $query = Client::where('id', $clientId);

        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereRaw('id = (SELECT id FROM orders o2 WHERE o2.client_id = orders.client_id ORDER BY created_at DESC LIMIT 1)')
                       ->whereHas('status', function($sq) {
                           $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                       });
                })
                ->orWhere(function($sub) use ($user) {
                    $sub->whereDoesntHave('orders', function($oq) {
                        $oq->whereHas('status', function($sq) {
                            $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                        });
                    })
                    ->where(function($inner) use ($user) {
                        $inner->where('agent_id', $user->id)
                              ->orWhereHas('whatsappConversations', function ($cq) use ($user) {
                                  $cq->where('agent_id', $user->id)
                                     ->where('status', 'open');
                              });
                    });
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
        $isAdmin = strtolower($user->role->description ?? '') === 'admin';

        $client = Client::findOrFail($clientId);

        // PRIVACY CHECK
        if (!$isAdmin) {
            $hasAccess = Client::where('id', $clientId)
                ->where(function ($q) use ($user) {
                    $q->whereHas('orders', function ($oq) use ($user) {
                        $oq->where('agent_id', $user->id)
                           ->whereRaw('id = (SELECT id FROM orders o2 WHERE o2.client_id = orders.client_id ORDER BY created_at DESC LIMIT 1)')
                           ->whereHas('status', function($sq) {
                               $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                           });
                    })
                    ->orWhere(function($sub) use ($user) {
                        $sub->whereDoesntHave('orders', function($oq) {
                            $oq->whereHas('status', function($sq) {
                                $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                            });
                        })
                        ->where(function($inner) use ($user) {
                            $inner->where('agent_id', $user->id)
                                  ->orWhereHas('whatsappConversations', function ($cq) use ($user) {
                                      $cq->where('agent_id', $user->id)
                                         ->where('status', 'open');
                                  });
                        });
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

        $message = WhatsappMessage::create([
            'order_id' => $latestOrder ? $latestOrder->id : null,
            'client_id' => $client->id,
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
        
        $user = Auth::user();
        if (!$user->relationLoaded('role')) $user->load('role');
        $isAdmin = strtolower($user->role->description ?? '') === 'admin';

        $client = Client::findOrFail($clientId);

        // PRIVACY CHECK
        if (!$isAdmin) {
            $hasAccess = Client::where('id', $clientId)
                ->where(function ($q) use ($user) {
                    $q->whereHas('orders', function ($oq) use ($user) {
                        $oq->where('agent_id', $user->id)
                           ->whereRaw('id = (SELECT id FROM orders o2 WHERE o2.client_id = orders.client_id ORDER BY created_at DESC LIMIT 1)')
                           ->whereHas('status', function($sq) {
                               $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                           });
                    })
                    ->orWhere(function($sub) use ($user) {
                        $sub->whereDoesntHave('orders', function($oq) {
                            $oq->whereHas('status', function($sq) {
                                $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                            });
                        })
                        ->where(function($inner) use ($user) {
                            $inner->where('agent_id', $user->id)
                                  ->orWhereHas('whatsappConversations', function ($cq) use ($user) {
                                      $cq->where('agent_id', $user->id)
                                         ->where('status', 'open');
                                  });
                        });
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
        $isAdmin = strtolower($user->role->description ?? '') === 'admin';

        // PRIVACY CHECK (same as show)
        $query = Client::where('id', $clientId);
        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereRaw('id = (SELECT id FROM orders o2 WHERE o2.client_id = orders.client_id ORDER BY created_at DESC LIMIT 1)')
                       ->whereHas('status', function($sq) {
                           $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                       });
                })
                ->orWhere(function($sub) use ($user) {
                    $sub->whereDoesntHave('orders', function($oq) {
                        $oq->whereHas('status', function($sq) {
                            $sq->where('description', '!=', OrderStatus::SIN_STOCK);
                        });
                    })
                    ->where(function($inner) use ($user) {
                        $inner->where('agent_id', $user->id)
                              ->orWhereHas('whatsappConversations', function ($cq) use ($user) {
                                  $cq->where('agent_id', $user->id)
                                     ->where('status', 'open');
                              });
                    });
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
}
