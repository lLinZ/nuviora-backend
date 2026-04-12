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
            'body' => 'nullable|string|required_without:template_name',
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
            $order = Order::where('order_id', $request->order_id)->first();
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

        // Normalize template name (remove spaces, lowercase) to be more robust for n8n
        $templateName = $request->template_name;
        $components = [];
        $renderedBody = $request->body ?? "Plantilla: {$templateName}";
        $service = new WhatsAppService();
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
                Log::info("EXTERNAL_WA: Preparation Template " . $templateName, ['vars' => $vars]);
                // RENDER BEFORE CREATING RECORD
                $renderedBody = $tpl->render($vars);
                
                if (!empty($tpl->meta_components)) {
                    Log::debug("EXTERNAL_WA: Using technical definition for {$templateName}", ['meta_components' => $tpl->meta_components]);
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
                                if ($val === '') {
                                    Log::warning("EXTERNAL_WA: Missing variable for index {$idx} in template {$templateName}");
                                }
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
                Log::warning("EXTERNAL_WA: Template NOT found in local DB: " . $templateName . ". Using direct vars fallback.");
                // FALLBACK: Si no existe en la BD local, enviamos las variables directamente al BODY
                if (!empty($vars)) {
                    $parameters = array_map(function($v) {
                        return ['type' => 'text', 'text' => (string)$v];
                    }, $vars);
                    
                    $components[] = [
                        'type' => 'body',
                        'parameters' => $parameters
                    ];
                }
            }
        }

        // 3. Log message using the translated localOrderId and rendered body
        $message = WhatsappMessage::create([
            'order_id' => $localOrderId,
            'client_id' => $client->id,
            'body' => $renderedBody,
            'is_from_client' => false,
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        if ($request->filled('template_name')) {
            $result = $service->sendTemplate($client->phone, $templateName, 'es', $components);
        } else {
            // Raw body message
            $result = $service->sendMessage($client->phone, $message->body);
        }

        // 3. Update status
        if ($result && isset($result['messages'][0]['id'])) {
            $message->update(['message_id' => $result['messages'][0]['id'], 'status' => 'sent']);
            
            // Trigger event for real-time frontend update if needed
            event(new \App\Events\WhatsappMessageReceived($message));
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'is_window_open' => $client->isWhatsappWindowOpen(),
                'external_id' => $result['messages'][0]['id']
            ], 201);
        }

        $message->update(['status' => 'failed']);
        Log::error("EXTERNAL_WA_ERROR: " . json_encode($result));
        
        return response()->json([
            'success' => false,
            'is_window_open' => $client->isWhatsappWindowOpen(),
            'error' => $result,
            'message_id' => $message->id
        ], 500);
    }

    /**
     * Get a comprehensive snapshot for n8n to decide how to proceed.
     * GET /check-window?phone=...&order_id=...
     */
    public function checkWindow(Request $request)
    {
        $phone = $request->query('phone');
        $orderId = $request->query('order_id');

        $client = null;
        $order = null;

        // 1. Find by Order ID (numeric or Shopify string)
        if ($orderId) {
            $order = Order::where('order_id', $orderId)
                ->orWhere('id', $orderId)
                ->first();
            if ($order) {
                $client = $order->client;
            }
        }

        // 2. Find by Phone if not found by order
        if (!$client && $phone) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
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
