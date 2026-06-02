<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InternalConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function messages()
    {
        return $this->hasMany(InternalMessage::class, 'conversation_id');
    }

    public function lastMessage()
    {
        return $this->hasOne(InternalMessage::class, 'conversation_id')->latestOfMany();
    }

    /**
     * ¿Es este usuario participante? Vendedora (agent_id) o agencia (agency_id)
     * de la orden asociada.
     */
    public function hasParticipant($userId): bool
    {
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();
        if (!$order) return false;

        return (int) $order->agent_id === (int) $userId
            || (int) $order->agency_id === (int) $userId;
    }
}
