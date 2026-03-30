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
    }

    public function broadcastWith(): array
    {
        return [
            'message' => array_merge($this->message->toArray(), [
                'client_id' => $this->message->client_id ?? $this->message->order?->client_id,
                'agent_id'  => $this->message->client?->agent_id ?? $this->message->order?->agent_id,
                'agency_id' => $this->message->order?->agency_id,
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

