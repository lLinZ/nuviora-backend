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
                Log::warning("EXTERNAL_WA: Template NOT found in local DB: " . $templateName);
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
                'external_id' => $result['messages'][0]['id']
            ], 201);
        }

        $message->update(['status' => 'failed']);
        Log::error("EXTERNAL_WA_ERROR: " . json_encode($result));
        
        return response()->json([
            'success' => false,
            'error' => $result,
            'message_id' => $message->id
        ], 500);
    }
}
