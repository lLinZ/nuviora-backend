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
    public function index(Request $request)
    {
        $user = Auth::user();
        $isAdmin = $user->role && in_array($user->role->description, ['Admin', 'Gerente', 'Master', 'SuperAdmin']);
        $search = $request->query('search');
        $query = Client::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('agent_id', $user->id)
                ->orWhereHas('orders', function ($oq) use ($user) {
                    $oq->where('agent_id', $user->id)
                       ->whereHas('status', function($sq) {
                           $sq->whereNotIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                       });
                });
            });
        }

        $paginator = $query->withCount(['whatsappMessages as unread_count' => function ($q) {
                $q->where('is_from_client', true)->where('status', '!=', 'read');
            }])
            ->with(['agent', 'whatsappConversations' => function ($q) {
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
                    'order' => $client->orders->first(),
                    'agent' => $client->agent,
                    'conversation' => $client->whatsappConversations->first(),
                ]
            ];
        });

        return response()->json($paginator);
    }

    public function show($clientId)
    {
        return response()->json(WhatsappMessage::where('client_id', $clientId)->orderBy('created_at', 'asc')->get());
    }

    public function store(Request $request, $clientId)
    {
        $request->validate([
            'body' => 'required|string',
            'template_name' => 'nullable|string',
            'vars' => 'nullable|array',
        ]);

        $client = Client::findOrFail($clientId);
        
        if (!$request->input('is_from_client', false) && !$request->filled('template_name')) {
            if (!$client->isWhatsappWindowOpen()) {
                return response()->json(['message' => 'Ventana cerrada'], 403);
            }
        }

        $message = WhatsappMessage::create([
            'client_id' => $client->id,
            'body' => $request->body,
            'is_from_client' => false,
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        $service = new \App\Services\WhatsAppService();
        if ($request->filled('template_name')) {
            $components = [];
            $tpl = \App\Models\WhatsappTemplate::where('name', $request->template_name)->first();

            if ($tpl && !empty($tpl->meta_components)) {
                $vars = $request->vars ?? [];
                Log::error("DEBUG_WA: Iniciando envio de plantilla " . $request->template_name);
                Log::error("DEBUG_WA: Vars recibidas: " . json_encode($vars));

                foreach ($tpl->meta_components as $component) {
                    $type = strtoupper($component['type'] ?? '');
                    if (!in_array($type, ['HEADER', 'BODY'])) continue;

                    $text = $component['text'] ?? '';
                    preg_match_all('/\{\{(\d+)\}\}/u', $text, $matches);
                    
                    $parameters = [];
                    if (!empty($matches[1])) {
                        foreach ($matches[1] as $placeholderNum) {
                            $idx = (int)$placeholderNum - 1;
                            $parameters[] = ['type' => 'text', 'text' => (string)($vars[$idx] ?? '')];
                        }
                    } else if ($type === 'HEADER' && count($vars) > 0) {
                        Log::error("DEBUG_WA: Forzando Header parameter pos 0");
                        $parameters[] = ['type' => 'text', 'text' => (string)$vars[0]];
                    }

                    if (!empty($parameters)) {
                        $components[] = [
                            'type' => strtolower($type),
                            'parameters' => $parameters
                        ];
                    }
                }
                Log::error("DEBUG_WA: Payload final: " . json_encode($components));
            }

            $result = $service->sendTemplate($client->phone, $request->template_name, 'es', $components);
        } else {
            $result = $service->sendMessage($client->phone, $message->body);
        }

        if ($result && isset($result['messages'][0]['id'])) {
            $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
        } else {
            $message->update(['status' => 'failed']);
            Log::error("DEBUG_WA: Error de Meta: " . json_encode($result));
        }

        $client->update(['last_interaction_at' => now()]);
        return response()->json($message->load('client'), 201);
    }

    public function sendMedia(Request $request, $clientId)
    {
        $file = $request->file('file');
        $path = $file->store('whatsapp_media', 'public');
        $fullPath = storage_path('app/public/' . $path);
        
        $service = new \App\Services\WhatsAppService();
        $mime = $file->getMimeType();
        $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'document');
        
        $upload = $service->uploadMedia($fullPath, $type);
        if ($upload && isset($upload['id'])) {
            $client = Client::findOrFail($clientId);
            $send = $service->sendMedia($client->phone, $upload['id'], $type, $request->caption);
            if ($send && isset($send['messages'][0]['id'])) {
                $msg = WhatsappMessage::create([
                    'client_id' => $client->id,
                    'message_id' => $send['messages'][0]['id'],
                    'body' => $request->caption ?? "Archivo {$type}",
                    'status' => 'sent',
                    'sent_at' => now(),
                    'media' => ['link' => asset('storage/' . $path), 'type' => $type]
                ]);
                return response()->json($msg, 201);
            }
        }
        return response()->json(['message' => 'Error'], 500);
    }
}
