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
    public ?string $bucket;

    public function __construct(int $clientId, ?int $agentId = null, ?string $bucket = null)
    {
        $this->clientId = $clientId;
        $this->agentId  = $agentId;
        $this->bucket   = $bucket;
    }

    public function broadcastWith(): array
    {
        return [
            'client_id' => $this->clientId,
            'conversation_bucket' => $this->bucket,
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
