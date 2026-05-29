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
        // 'products' se carga para poder calcular stock_elsewhere en broadcastWith().
        $this->order = $order->load(['status', 'client', 'agent', 'agency', 'deliverer', 'shop', 'products']);
    }

    /**
     * Payload enviado al frontend. Incluimos stock_elsewhere cuando la orden
     * está en "Sin Stock" para que el Kanban/Dialog muestre dónde sí hay stock.
     */
    public function broadcastWith(): array
    {
        $orderArray = $this->order->toArray();

        $orderArray['stock_elsewhere'] = ($this->order->status?->description === \App\Constants\OrderStatus::SIN_STOCK)
            ? $this->order->getStockAvailabilityElsewhere()
            : [];

        return ['order' => $orderArray];
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
