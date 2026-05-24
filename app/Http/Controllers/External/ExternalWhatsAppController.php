<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WhatsappTemplate;
use App\Models\WhatsappMessage;
use App\Models\Client;
use App\Models\Order;
use App\Services\WhatsAppService;
use App\Services\ConversationBucketService;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExternalWhatsAppController extends Controller
{
    /**
     * List available WhatsApp templates.
     */
    public function index()
    {
        $templates = WhatsappTemplate::orderBy('label')->get();
        return response()->json($templates);
    }

    /**
     * Send a WhatsApp message or template.
     * Expected fields: phone, template_name (optional), vars (optional), body (optional), order_id (optional)
     */
    public function send(Request $request)
    {
        $request->validate([
            'phone'         => 'required_without:to|string',
            'to'            => 'required_without:phone|string',
            'template_name' => 'nullable|string',
            'template'      => 'nullable|array',
            'vars'          => 'nullable|array',
            'body'          => 'nullable|string',
            'media'         => 'nullable|array',
            'order_id'      => 'nullable|integer',
            // message_type allows n8n to override. Default: outgoing_automated_message
            // Use 'outgoing_agent_message' only when a human is manually triggering via n8n
            'message_type'  => 'nullable|string|in:outgoing_agent_message,outgoing_automated_message',
        ]);

        // Resolve message_type — default is automated (templates/n8n automations)
        $messageType = $request->input('message_type', WhatsappMessage::TYPE_AUTOMATED);

        $phone = $request->input('phone') ?? $request->input('to');
        
        // Ensure phone format (basic cleaning)
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $last10 = substr($phone, -10);

        // 1. Find Client and Local Order ID
        $client = null;
        $localOrderId = null;
        if ($request->filled('order_id')) {
            // Search by Shopify order_id (string) OR by internal numeric id
            $order = Order::where('order_id', $request->order_id)
                ->orWhere('id', is_numeric($request->order_id) ? $request->order_id : null)
                ->first();
            if ($order) {
                $localOrderId = $order->id; // Internal numeric ID
                $client = $order->client;
            }
        }

        if (!$client) {
            $client = Client::where('phone', 'like', "%{$last10}")->first();
        }

        // 2. Create if not found
        if (!$client) {
            $client = Client::create([
                'phone' => $phone,
                'first_name' => 'Cliente',
                'last_name' => 'Externo',
                'customer_id' => null,
            ]);
        }

        $service = new WhatsAppService();
        $mediaResults = [];

        // 3. Handle Media (Images, Documents, etc.)
        $mediaUrls = $request->input('media', []);
        
        // Robustness: Handle case where n8n sends a single string with commas instead of a real array
        if (is_string($mediaUrls)) {
            $mediaUrls = explode(',', $mediaUrls);
        } elseif (is_array($mediaUrls) && count($mediaUrls) === 1 && str_contains($mediaUrls[0], ',')) {
            $mediaUrls = explode(',', $mediaUrls[0]);
        }

        if (!empty($mediaUrls)) {
            foreach ($mediaUrls as $url) {
                $url = trim($url);
                if (empty($url)) continue;

                // Determine type based on extension
                $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
                $type = 'document';
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) $type = 'image';
                elseif (in_array($ext, ['mp4', '3gp'])) $type = 'video';
                elseif (in_array($ext, ['mp3', 'ogg', 'aac'])) $type = 'audio';

                // Create DB Record — media stored as array to match model cast
                $msgModel = WhatsappMessage::create([
                    'order_id'     => $localOrderId,
                    'client_id'    => $client->id,
                    'body'         => "Archivo {$type}",
                    'media'        => ['link' => $url, 'type' => $type],
                    'is_from_client' => false,
                    'message_type' => $messageType,
                    'status'       => 'sending',
                    'sent_at'      => now(),
                ]);

                $res = $service->sendMediaByUrl($client->phone, $url, $type);
                if ($res && isset($res['messages'][0]['id'])) {
                    $msgModel->update(['message_id' => $res['messages'][0]['id'], 'status' => 'sent']);

                    // Only recalculate bucket if this is a real agent message (not automation)
                    $bucket = ($messageType === WhatsappMessage::TYPE_AGENT)
                        ? ConversationBucketService::recalculate($client->id)
                        : null;

                    $msgModel->setRelation('_bucket', $bucket);
                    event(new \App\Events\WhatsappMessageReceived($msgModel->fresh()->load('client', 'order')));
                    $mediaResults[] = ['url' => $url, 'success' => true, 'id' => $res['messages'][0]['id']];
                } else {
                    $msgModel->update(['status' => 'failed']);
                    Log::error("EXTERNAL_MEDIA_FAIL: " . json_encode($res));
                    $mediaResults[] = ['url' => $url, 'success' => false];
                }
            }
        }

        // 4. Handle Text or Template
        $templateData = $request->input('template');
        $hasTemplate = $request->filled('template_name') || ($templateData && is_array($templateData) && !empty($templateData['name']));

        if ($hasTemplate || $request->filled('body')) {
            $templateName = $request->template_name;
            $components = [];
            $langCode = 'es';

            if ($templateData && is_array($templateData)) {
                if (!$templateName) {
                    $templateName = $templateData['name'] ?? null;
                }
                if (isset($templateData['language']['code'])) {
                    $langCode = $templateData['language']['code'];
                }
                if (isset($templateData['components']) && is_array($templateData['components'])) {
                    $components = $templateData['components'];
                }
            }

            $renderedBody = $request->body ?? "Plantilla: {$templateName}";
            $tpl = null;
            $vars = $request->vars ?? [];

            if ($templateName) {
                $normalizedName = strtolower(str_replace(' ', '_', $templateName));
                $tpl = WhatsappTemplate::where('name', $normalizedName)
                    ->orWhere('name', $templateName)
                    ->orWhere('label', $templateName)
                    ->first();

                if ($tpl) {
                    $templateName = $tpl->name;
                    $renderedBody = $tpl->render($vars);
                    
                    if (!empty($tpl->language)) {
                        $langCode = $tpl->language;
                    }

                    // Build components from database template ONLY if they were not explicitly passed in the request
                    if (empty($components) && !empty($tpl->meta_components)) {
                        foreach ($tpl->meta_components as $component) {
                            $rawType = strtoupper($component['type'] ?? '');
                            // Support HEADER, BODY, and BUTTONS (any component type)
                            if (!in_array($rawType, ['HEADER', 'BODY', 'BUTTONS'])) continue;

                            $text = $component['text'] ?? '';
                            preg_match_all('/\{\{(\d+)\}\}/u', $text, $matches);
                            
                            $parameters = [];
                            if (!empty($matches[1])) {
                                foreach ($matches[1] as $placeholderNum) {
                                    $idx = (int)$placeholderNum - 1;
                                    $val = (string)($vars[$idx] ?? '');
                                    $parameters[] = ['type' => 'text', 'text' => $val];
                                }
                            } else if ($rawType === 'HEADER' && count($vars) > 0) {
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
                    // Fallback when template is not in DB and components were not explicitly passed
                    if (empty($components) && !empty($vars)) {
                        $parameters = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $vars);
                        $components[] = ['type' => 'body', 'parameters' => $parameters];
                    }
                }
            }

            // Create DB Record for text
            $message = WhatsappMessage::create([
                'order_id'       => $localOrderId,
                'client_id'      => $client->id,
                'body'           => $renderedBody,
                'is_from_client' => false,
                'message_type'   => $messageType,
                'status'         => 'sending',
                'sent_at'        => now(),
            ]);

            if ($templateName) {
                $result = $service->sendTemplate($client->phone, $templateName, $langCode, $components);
            } else {
                $result = $service->sendMessage($client->phone, $message->body);
            }

            // Update status
            if ($result && isset($result['messages'][0]['id'])) {
                $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
                $client->update(['last_interaction_at' => now()]);

                // REGLA CRÍTICA: Las automatizaciones NO mueven el bucket a follow_up.
                // Solo se recalcula si el message_type es outgoing_agent_message.
                $bucket = ($messageType === WhatsappMessage::TYPE_AGENT)
                    ? ConversationBucketService::recalculate($client->id)
                    : null;

                $message->setRelation('_bucket', $bucket);
                event(new \App\Events\WhatsappMessageReceived($message->fresh()->load('client', 'order')));
                
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'media'   => $mediaResults,
                    'is_window_open' => $client->isWhatsappWindowOpen(),
                ], 201);
            }

            $message->update(['status' => 'failed']);
            return response()->json([
                'success' => false,
                'error' => $result,
                'media' => $mediaResults,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'media' => $mediaResults,
            'is_window_open' => $client->isWhatsappWindowOpen(),
        ]);
    }

    /**
     * Get a comprehensive snapshot for n8n to decide how to proceed.
     * GET /check-window?phone=...&order_id=...&client_id=...
     *
     * Lookup priority: client_id > order_id > phone
     * Always prefer sending client_id from n8n when available to avoid
     * returning the wrong client when multiple clients share the same phone.
     */
    public function checkWindow(Request $request)
    {
        $internalId  = $request->input('internal_id');
        $shopId      = $request->input('shop_id');
        $orderId     = $request->input('order_id');
        $orderNumber = $request->input('order_number');
        $clientId    = $request->input('client_id');
        $phone       = $request->input('phone');

        $client = null;
        $order  = null;

        // Rule 1: Si llega internal_id, buscar exactamente orders.id = internal_id.
        // Validar que esa orden también coincida con shop_id y client_id.
        if ($internalId) {
            $order = Order::find($internalId);
            if (!$order) {
                return response()->json(['success' => false, 'message' => "Order with ID {$internalId} not found."], 404);
            }
            
            if ($shopId && (int)$order->shop_id !== (int)$shopId) {
                return response()->json(['success' => false, 'message' => "Order shop mismatch (expected {$shopId}, got {$order->shop_id})."], 400);
            }
            
            if ($clientId && (int)$order->client_id !== (int)$clientId) {
                return response()->json(['success' => false, 'message' => "Order client mismatch (expected {$clientId}, got {$order->client_id})."], 400);
            }
            
            $client = $order->client;
        }

        // Rule 3: Si no llega internal_id, buscar por shop_id + order_id.
        if (!$order && $shopId && $orderId) {
            $orders = Order::where('shop_id', $shopId)
                ->where(function($q) use ($orderId) {
                    $q->where('order_id', $orderId)
                      ->orWhere('id', is_numeric($orderId) ? $orderId : null);
                })
                ->get();

            if ($orders->count() > 1) {
                return response()->json(['success' => false, 'message' => "Ambiguity: Multiple orders found for shop_id {$shopId} and order_id {$orderId}."], 400);
            }
            
            $order = $orders->first();
            if ($order) {
                $client = $order->client;
            }
        }

        // Rule 4: Si no encuentra, buscar por shop_id + order_number.
        if (!$order && $shopId && $orderNumber) {
            $orders = Order::where('shop_id', $shopId)
                ->where('order_number', $orderNumber)
                ->get();

            if ($orders->count() > 1) {
                return response()->json(['success' => false, 'message' => "Ambiguity: Multiple orders found for shop_id {$shopId} and order_number {$orderNumber}."], 400);
            }
            
            $order = $orders->first();
            if ($order) {
                $client = $order->client;
            }
        }

        // Rule 5: Como última opción, usar client_id + phone + última orden activa.
        if (!$order) {
            if ($clientId) {
                $client = Client::find($clientId);
            }
            if (!$client && $phone) {
                $phoneClean = preg_replace('/[^0-9]/', '', $phone);
                $last10 = substr($phoneClean, -10);
                $client = Client::where('phone', 'like', "%{$last10}")->first();
            }

            if ($client) {
                $query = Order::where('client_id', $client->id);
                if ($shopId) {
                    $query->where('shop_id', $shopId);
                }

                // Filter by active status (excluding Entregado, Cancelado, Rechazado)
                $query->whereHas('status', function($q) {
                    $q->whereNotIn('description', ['Entregado', 'Cancelado', 'Rechazado']);
                });

                $orders = $query->orderBy('created_at', 'desc')->get();

                if ($orders->count() > 1) {
                    return response()->json(['success' => false, 'message' => "Ambiguity: Multiple active orders found for client {$client->id} and shop {$shopId}."], 400);
                }

                $order = $orders->first();
            }
        }

        // Enforce: Never return an order from another client or another shop.
        if ($order) {
            $client = $order->client;
            if ($clientId && (int)$order->client_id !== (int)$clientId) {
                return response()->json(['success' => false, 'message' => 'Security check failed: Order client mismatch.'], 400);
            }
            if ($shopId && (int)$order->shop_id !== (int)$shopId) {
                return response()->json(['success' => false, 'message' => 'Security check failed: Order shop mismatch.'], 400);
            }
            if ($phone) {
                $phoneClean = preg_replace('/[^0-9]/', '', $phone);
                $last10 = substr($phoneClean, -10);
                if ($client) {
                    $clientPhoneClean = preg_replace('/[^0-9]/', '', $client->phone);
                    $clientLast10 = substr($clientPhoneClean, -10);
                    if ($clientLast10 !== $last10) {
                        return response()->json(['success' => false, 'message' => 'Security check failed: Client phone mismatch.'], 400);
                    }
                }
            }
        }

        if (!$client) {
            // If we couldn't resolve the client by this stage
            if ($clientId) {
                $client = Client::find($clientId);
            }
            if (!$client && $phone) {
                $phoneClean = preg_replace('/[^0-9]/', '', $phone);
                $last10 = substr($phoneClean, -10);
                $client = Client::where('phone', 'like', "%{$last10}")->first();
            }
        }

        if (!$client) {
            return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        }

        // 4. Products Formatting
        $products = [];
        if ($order) {
            $rateBinance = (float) Setting::get('rate_binance_usd', 0);
            $rateBcv     = (float) Setting::get('rate_bcv_usd', 0);
            $rateEur     = (float) Setting::get('rate_bcv_eur', 0);

            $products = $order->products->map(function($op) use ($rateBinance, $rateBcv, $rateEur) {
                $priceUsd = (float) ($op->price ?? 0);
                $priceVesBinance = round($priceUsd * $rateBinance, 2);
                
                return [
                    'name'              => $op->showable_name ?: ($op->name ?: $op->title),
                    'qty'               => $op->quantity,
                    'price_usd'         => $priceUsd,
                    'price_ves'         => $priceVesBinance, // Default VES (Binance)
                    'price_ves_binance' => $priceVesBinance,
                    'price_ves_bcv'     => round($priceUsd * $rateBcv, 2),
                    'price_eur'         => $rateEur > 0 ? round($priceVesBinance / $rateEur, 2) : 0,
                    'price'             => $priceUsd // Legacy support
                ];
            });
        }

        // 5. Latest Messages Formatting
        $messages = WhatsappMessage::where('client_id', $client->id)
            ->latest()
            ->take(5)
            ->get()
            ->map(function($m) {
                return [
                    'body' => $m->body,
                    'sender' => $m->is_from_client ? 'client' : 'agent',
                    'date' => $m->sent_at,
                    'status' => $m->status
                ];
            });

        // 6. Advanced Automation check for n8n flow tracking (Enhanced for Fran)
        $lastAutomated = WhatsappMessage::where('client_id', $client->id)
            ->where('is_from_client', false)
            ->where(function($q) {
                // Consider as automated: explicit message_type = automated OR body contains "Plantilla:"
                $q->where('message_type', WhatsappMessage::TYPE_AUTOMATED)
                  ->orWhere('body', 'like', 'Plantilla:%');
            })
            ->latest('sent_at')
            ->first();

        $hasClientResponseSinceLastAutomated = false;
        $hasAgentResponseSinceLastAutomated = false;

        if ($lastAutomated) {
            $hasClientResponseSinceLastAutomated = WhatsappMessage::where('client_id', $client->id)
                ->where('is_from_client', true)
                ->where('sent_at', '>', $lastAutomated->sent_at)
                ->exists();

            $hasAgentResponseSinceLastAutomated = WhatsappMessage::where('client_id', $client->id)
                ->where('is_from_client', false)
                ->where('message_type', WhatsappMessage::TYPE_AGENT)
                ->where('sent_at', '>', $lastAutomated->sent_at)
                ->exists();
        }

        return response()->json([
            'success' => true,
            'client_id' => $client->id,
            'order_id' => $order ? $order->order_id : null,
            'phone' => $client->phone,
            'client' => [
                'id' => $client->id,
                'name' => "{$client->first_name} {$client->last_name}",
                'phone' => $client->phone,
                'is_window_open' => $client->isWhatsappWindowOpen(),
            ],
            'order' => $order ? [
                'internal_id' => $order->id,
                'order_id' => $order->order_id,
                'status' => $order->status ? $order->status->description : 'N/A',
                'status_id' => $order->status_id,
                'total' => (float) $order->current_total_price,
                'total_usd' => (float) $order->current_total_price,
                'total_ves' => (float) $order->ves_price, // ves_price is binance
                'total_ves_binance' => (float) $order->ves_price,
                'total_ves_bcv' => round($order->current_total_price * (float) Setting::get('rate_bcv_usd', 0), 2),
                'total_eur' => ((float) Setting::get('rate_bcv_eur', 0) > 0) 
                                ? round($order->ves_price / (float) Setting::get('rate_bcv_eur', 0), 2) 
                                : 0,
                'items' => $products,
                'store_is_open' => $order->is_store_open,
            ] : null,
            'rates' => [
                'binance_usd' => (float) Setting::get('rate_binance_usd', 0),
                'bcv_usd'     => (float) Setting::get('rate_bcv_usd', 0),
                'bcv_eur'     => (float) Setting::get('rate_bcv_eur', 0),
            ],
            'latest_messages' => $messages,
            'automation_check' => [
                'last_automated_message_body' => $lastAutomated ? $lastAutomated->body : null,
                'last_automated_message_date' => $lastAutomated ? $lastAutomated->sent_at?->toDateTimeString() : null,
                'has_client_response_since_last_automated' => $hasClientResponseSinceLastAutomated,
                'has_agent_response_since_last_automated' => $hasAgentResponseSinceLastAutomated,
                'should_stop_flow' => $hasClientResponseSinceLastAutomated || $hasAgentResponseSinceLastAutomated
            ],
            'template_tracking' => [
                'last_template_sent' => $lastAutomated ? $lastAutomated->body : null,
                'last_template_sent_at' => $lastAutomated ? $lastAutomated->sent_at?->toDateTimeString() : null,
                'has_client_responded_since' => $hasClientResponseSinceLastAutomated,
                'should_send_next_template' => $lastAutomated && !$hasClientResponseSinceLastAutomated && !$hasAgentResponseSinceLastAutomated
            ],
            'timestamp' => now()->toDateTimeString()
        ]);
    }

    /**
     * List folders and files for n8n.
     * GET /media-library?path=...
     */
    public function listMedia(Request $request)
    {
        $path = $request->query('path', '');
        
        // Sanitization logic (to match internal explorer security)
        $path = str_replace(['..', './', '.\\'], '', $path);
        $path = trim($path, '/\\');
        
        $baseName = 'media_library';
        $fullPath = $baseName . ($path ? '/' . $path : '');
        $disk = 'public';

        if (!\Storage::disk($disk)->exists($fullPath)) {
            return response()->json(['success' => false, 'message' => 'Path not found'], 404);
        }

        $directories = \Storage::disk($disk)->directories($fullPath);
        $files = \Storage::disk($disk)->files($fullPath);

        $items = [];

        foreach ($directories as $dir) {
            $items[] = [
                'name' => basename($dir),
                'type' => 'directory',
                'path' => trim(str_replace($baseName, '', $dir), '/')
            ];
        }

        foreach ($files as $file) {
            $items[] = [
                'name' => basename($file),
                'type' => 'file',
                'path' => trim(str_replace($baseName, '', $file), '/'),
                'url' => \Storage::disk($disk)->url($file),
                'size' => \Storage::disk($disk)->size($file)
            ];
        }

        return response()->json([
            'success' => true,
            'current_path' => $path,
            'items' => $items
        ]);
    }
}
