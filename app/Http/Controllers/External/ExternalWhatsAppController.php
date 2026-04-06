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

        // 1. Find or create Client
        $client = Client::where('phone', 'like', "%{$phone}")->first();
        if (!$client) {
            $client = Client::create([
                'phone' => $phone,
                'first_name' => 'Cliente',
                'last_name' => 'Externo',
            ]);
        }

        // 2. Log message
        $message = WhatsappMessage::create([
            'order_id' => $request->order_id,
            'client_id' => $client->id,
            'body' => $request->body ?? "Plantilla: {$request->template_name}",
            'is_from_client' => false,
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        $service = new WhatsAppService();
        $components = [];

        if ($request->filled('template_name')) {
            $tpl = WhatsappTemplate::where('name', $request->template_name)->first();

            if ($tpl && !empty($tpl->meta_components)) {
                $vars = $request->vars ?? [];
                Log::info("EXTERNAL_WA: Preparation Template " . $request->template_name);

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
                        $parameters[] = ['type' => 'text', 'text' => (string)$vars[0]];
                    }

                    if (!empty($parameters)) {
                        $components[] = [
                            'type' => strtolower($rawType),
                            'parameters' => $parameters
                        ];
                    }
                }
            } else {
                // Fallback for missing mapping
                if ($request->has('vars')) {
                    $parameters = array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $request->vars);
                    $components[] = ['type' => 'body', 'parameters' => $parameters];
                }
            }

            $result = $service->sendTemplate($client->phone, $request->template_name, 'es', $components);
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
