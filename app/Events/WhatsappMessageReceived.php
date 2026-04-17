<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsappMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    /**
     * Create a new event instance.
     */
    public function __construct($message)
    {
        $this->message = $message;
        if (!$this->message->relationLoaded('order')) {
            $this->message->load('order');
        }
        // Cargar el cliente para que el frontend pueda mostrar su nombre en notificaciones
        if (!$this->message->relationLoaded('client')) {
            $this->message->load('client');
        }
    }

    public function broadcastWith(): array
    {
        $conv = \App\Models\WhatsappConversation::where('client_id', $this->message->client_id)
            ->orderByDesc('updated_at')
            ->first();
        $bucket = $conv?->conversation_bucket ?? 'follow_up';

        $client = $this->message->client;
        $clientId = $this->message->client_id ?? $this->message->order?->client_id;

        return [
            'message' => array_merge($this->message->toArray(), [
                'client_id'           => $clientId,
                'agent_id'            => $client?->agent_id ?? $this->message->order?->agent_id,
                'agency_id'           => $this->message->order?->agency_id,
                'conversation_bucket' => $bucket,
                // Incluir datos del cliente para el toast de notificacion y para identificar el contacto
                'client' => $client ? [
                    'id'    => $client->id,
                    'names' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                    'first_name' => $client->first_name,
                    'last_name'  => $client->last_name,
                    'phone'      => $client->phone,
                ] : null,
            ])
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $order = $this->message->order;
        $channels = [
            new PrivateChannel('orders'),
            new PrivateChannel('whatsapp')
        ];

        if ($order && $order->agency_id) {
            $channels[] = new PrivateChannel('orders.agency.' . $order->agency_id);
        }
        if ($order && $order->agent_id) {
            $channels[] = new PrivateChannel('orders.agent.' . $order->agent_id);
        }
        if ($order && $order->deliverer_id) {
            $channels[] = new PrivateChannel('orders.deliverer.' . $order->deliverer_id);
        }
        if ($order) {
            $channels[] = new PrivateChannel('orders.' . $order->id);
        }

        return $channels;
    }
}

