<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    /**
     * Create a new event instance.
     */
    public function __construct($order)
    {
        // 🔄 Asegurarnos de tener las relaciones cargadas para el frontend
        $this->order = $order->load(['status', 'client', 'agent', 'agency', 'deliverer', 'shop']);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('orders')];

        if ($this->order->agency_id) {
            $channels[] = new PrivateChannel('orders.agency.' . $this->order->agency_id);
        }
        if ($this->order->agent_id) {
            $channels[] = new PrivateChannel('orders.agent.' . $this->order->agent_id);
        }
        if ($this->order->deliverer_id) {
            $channels[] = new PrivateChannel('orders.deliverer.' . $this->order->deliverer_id);
        }

        return $channels;
    }
}
