<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WhatsappTemplate;
use App\Models\WhatsappMessage;
use App\Models\Client;
use App\Models\Order;
use App\Services\WhatsAppService;
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
            'phone' => 'required|string',
            'template_name' => 'nullable|string',
            'vars' => 'nullable|array',
            'body' => 'nullable|string|required_without_all:template_name,media',
            'media' => 'nullable|array',
            'order_id' => 'nullable|integer'
        ]);

        $phone = $request->phone;
        
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
                    'order_id' => $localOrderId,
                    'client_id' => $client->id,
                    'body' => "Archivo {$type}",
                    'media' => ['link' => $url, 'type' => $type],
                    'is_from_client' => false,
                    'status' => 'sending',
                    'sent_at' => now(),
                ]);

                $res = $service->sendMediaByUrl($client->phone, $url, $type);
                if ($res && isset($res['messages'][0]['id'])) {
                    $msgModel->update(['message_id' => $res['messages'][0]['id'], 'status' => 'sent']);
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
        if ($request->filled('template_name') || $request->filled('body')) {
            $templateName = $request->template_name;
            $components = [];
            $renderedBody = $request->body ?? "Plantilla: {$templateName}";
            $tpl = null;
            $vars = $request->vars ?? [];

            if ($request->filled('template_name')) {
                $normalizedName = strtolower(str_replace(' ', '_', $templateName));
                $tpl = WhatsappTemplate::where('name', $normalizedName)
                    ->orWhere('name', $templateName)
                    ->orWhere('label', $templateName)
                    ->first();

                if ($tpl) {
                    $templateName = $tpl->name;
                    $renderedBody = $tpl->render($vars);
                    
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
                    // Fallback
                    if (!empty($vars)) {
                        $parameters = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $vars);
                        $components[] = ['type' => 'body', 'parameters' => $parameters];
                    }
                }
            }

            // Create DB Record for text
            $message = WhatsappMessage::create([
                'order_id' => $localOrderId,
                'client_id' => $client->id,
                'body' => $renderedBody,
                'is_from_client' => false,
                'status' => 'sending',
                'sent_at' => now(),
            ]);

            if ($request->filled('template_name')) {
                // Use the template's own language code; fallback to 'es' if not stored
                $langCode = ($tpl && !empty($tpl->language)) ? $tpl->language : 'es';
                $result = $service->sendTemplate($client->phone, $templateName, $langCode, $components);
            } else {
                $result = $service->sendMessage($client->phone, $message->body);
            }

            // Update status
            if ($result && isset($result['messages'][0]['id'])) {
                $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
                $client->update(['last_interaction_at' => now()]);
                event(new \App\Events\WhatsappMessageReceived($message->fresh()->load('client', 'order')));
                
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'media' => $mediaResults,
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
        $phone    = $request->query('phone');
        $orderId  = $request->query('order_id');
        $clientId = $request->query('client_id');

        $client = null;
        $order  = null;

        // 1. HIGHEST PRIORITY: Find directly by client_id
        if ($clientId && is_numeric($clientId)) {
            $client = Client::find((int) $clientId);
        }

        // 2. Find by Order ID (numeric or Shopify string)
        if (!$client && $orderId) {
            $order = Order::where('order_id', $orderId)
                ->orWhere('id', $orderId)
                ->first();
            if ($order) {
                $client = $order->client;
            }
        }

        // 3. Fallback: Find by Phone (last 10 digits)
        //    NOTE: Multiple clients may share the same phone — this returns
        //    the first match only. Always prefer sending client_id from n8n.
        if (!$client && $phone) {
            $phone  = preg_replace('/[^0-9]/', '', $phone);
            $last10 = substr($phone, -10);
            $client = Client::where('phone', 'like', "%{$last10}")->first();
        }

        if (!$client) {
            return response()->json(['success' => false, 'message' => 'Client not found'], 404);
        }

        // 3. Get latest order if none specified
        if (!$order) {
            $order = Order::where('client_id', $client->id)->latest()->first();
        }

        // 4. Products Formatting
        $products = [];
        if ($order) {
            $products = $order->products->map(function($op) {
                return [
                    'name' => $op->name ?? $op->title,
                    'qty'  => $op->quantity,
                    'price'=> $op->price
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

        return response()->json([
            'success' => true,
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
                'total' => $order->current_total_price,
                'items' => $products,
                'store_is_open' => $order->is_store_open,
            ] : null,
            'latest_messages' => $messages,
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
