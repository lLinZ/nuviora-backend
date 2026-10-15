<?php

namespace App\Events;

use App\Models\WhatsappMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Meta avisó que un mensaje que salió se entregó, se leyó o falló: el chat abierto lo marca sin recargar. */
class WhatsappMessageStatus implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public WhatsappMessage $message)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'id'           => $this->message->id,
            'message_id'   => $this->message->message_id,
            'client_id'    => $this->message->client_id,
            'order_id'     => $this->message->order_id,
            'status'       => $this->message->status,
            'error_code'   => $this->message->error_code,
            'error_reason' => $this->message->error_reason,
        ];
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('whatsapp')];

        if ($this->message->order_id) {
            $channels[] = new PrivateChannel('orders.' . $this->message->order_id);
        }

        return $channels;
    }
}
