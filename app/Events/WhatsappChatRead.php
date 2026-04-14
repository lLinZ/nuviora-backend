<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsappChatRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $clientId;
    public ?int $agentId;

    public function __construct(int $clientId, ?int $agentId = null)
    {
        $this->clientId = $clientId;
        $this->agentId  = $agentId;
    }

    public function broadcastWith(): array
    {
        return [
            'client_id' => $this->clientId,
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('whatsapp'),
        ];

        if ($this->agentId) {
            $channels[] = new PrivateChannel('orders.agent.' . $this->agentId);
        }

        return $channels;
    }
}
