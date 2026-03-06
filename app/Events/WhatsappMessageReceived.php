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
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $order = $this->message->order;
        $channels = [new PrivateChannel('orders')];

        if ($order->agency_id) {
            $channels[] = new PrivateChannel('orders.agency.' . $order->agency_id);
        }
        if ($order->agent_id) {
            $channels[] = new PrivateChannel('orders.agent.' . $order->agent_id);
        }
        if ($order->deliverer_id) {
            $channels[] = new PrivateChannel('orders.deliverer.' . $order->deliverer_id);
        }

        return $channels;
    }
}

