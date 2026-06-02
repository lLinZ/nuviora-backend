<?php

namespace App\Events;

use App\Models\InternalMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InternalMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public InternalMessage $message;

    public function __construct(InternalMessage $message)
    {
        $this->message = $message->loadMissing([
            'sender.role',
            'sender.cities:id,name,agency_id',
            'conversation.order:id,agent_id,agency_id',
        ]);
    }

    public function broadcastWith(): array
    {
        $sender = $this->message->sender;

        return [
            'message' => [
                'id'              => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'sender_id'       => $this->message->sender_id,
                'body'            => $this->message->body,
                'read_at'         => $this->message->read_at,
                'created_at'      => $this->message->created_at,
                'sender' => $sender ? [
                    'id'   => $sender->id,
                    'name' => $sender->chatDisplayName(),
                ] : null,
            ],
        ];
    }

    /**
     * Emite al canal del hilo (para anexar en vivo) y a los canales personales
     * de los participantes de la orden (para el contador de no-leídos / campanita).
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('internal-chat.' . $this->message->conversation_id)];

        $order = $this->message->conversation?->order;
        if ($order) {
            if ($order->agent_id)  $channels[] = new PrivateChannel('App.Models.User.' . $order->agent_id);
            if ($order->agency_id) $channels[] = new PrivateChannel('App.Models.User.' . $order->agency_id);
        }

        return $channels;
    }
}
