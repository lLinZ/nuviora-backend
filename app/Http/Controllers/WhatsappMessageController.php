<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WhatsappMessageController extends Controller
{
    public function index(Request $request, $orderId)
    {
        $perPage = $request->query('per_page', 20);

        // Fetch the current order to get its client_id
        $order = \App\Models\Order::findOrFail($orderId);
        $clientId = $order->client_id;

        // Fetch messages for ALL orders belonging to this client
        $messages = \App\Models\WhatsappMessage::whereHas('order', function($q) use ($clientId) {
            $q->where('client_id', $clientId);
        })
        ->orderBy('created_at', 'desc')
        ->paginate($perPage);

        return response()->json($messages);
    }

    public function store(Request $request, $orderId)
    {
        $request->validate([
            'body' => 'required|string',
            'is_from_client' => 'boolean',
        ]);

        $order = \App\Models\Order::with('client')->findOrFail($orderId);

        // 1. Persist to DB
        $message = \App\Models\WhatsappMessage::create([
            'order_id' => $order->id,
            'body' => $request->body,
            'is_from_client' => $request->input('is_from_client', false),
            'status' => 'sending',
            'sent_at' => now(),
        ]);

        // 2. If it's from us (shop), send via Meta API
        if (!$message->is_from_client) {
            $service = new \App\Services\WhatsAppService();
            $result = $service->sendMessage($order->client->phone, $message->body);

            if ($result && isset($result['messages'][0]['id'])) {
                $message->update([
                    'message_id' => $result['messages'][0]['id'],
                    'status' => 'sent'
                ]);
            } else {
                $message->update(['status' => 'failed']);
            }
        }

        // 3. Broadcast real-time
        event(new \App\Events\WhatsappMessageReceived($message));

        return response()->json($message, 201);
    }

    public function markAsRead($orderId)

    {
        \App\Models\WhatsappMessage::where('order_id', $orderId)
            ->where('is_from_client', true)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        // Refresh order to broadcast new unread count if needed
        $order = \App\Models\Order::find($orderId);
        if ($order) {
            $order->load(['status', 'client', 'agent', 'agency', 'deliverer']);
            event(new \App\Events\OrderUpdated($order));
        }

        return response()->json(['status' => 'success']);
    }
}

