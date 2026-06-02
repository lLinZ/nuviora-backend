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
        $this->message = $message->loadMissing(['sender:id,names,surnames,role_id', 'conversation', 'order:id']);
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
                'order_id'        => $this->message->order_id,
                'read_at'         => $this->message->read_at,
                'created_at'      => $this->message->created_at,
                'sender' => $sender ? [
                    'id'    => $sender->id,
                    'names' => trim(($sender->names ?? '') . ' ' . ($sender->surnames ?? '')),
                ] : null,
            ],
        ];
    }

    /**
     * Emite al canal del hilo (para anexar en vivo) y a los canales personales
     * de ambos participantes (para el contador de no-leídos / campanita).
     */
    public function broadcastOn(): array
    {
        $conv = $this->message->conversation;

        return [
            new PrivateChannel('internal-chat.' . $this->message->conversation_id),
            new PrivateChannel('App.Models.User.' . $conv->vendedor_id),
            new PrivateChannel('App.Models.User.' . $conv->agency_id),
        ];
    }
}
