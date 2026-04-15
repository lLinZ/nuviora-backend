<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    // ─── Message type constants ───────────────────────────────────────────────
    // incoming_message         → cliente escribió (activa requires_attention)
    // outgoing_agent_message   → vendedora respondió manualmente (activa follow_up)
    // outgoing_automated_message → template/n8n (NO cuenta como respuesta humana)
    // system_event             → cambio de status, asignación, etc. (no afecta bucket)

    const TYPE_INCOMING   = 'incoming_message';
    const TYPE_AGENT      = 'outgoing_agent_message';
    const TYPE_AUTOMATED  = 'outgoing_automated_message';
    const TYPE_SYSTEM     = 'system_event';

    protected $fillable = [
        'order_id',
        'client_id',
        'message_id',
        'body',
        'is_from_client',
        'message_type',
        'status',
        'media',
        'sent_at'
    ];

    protected $casts = [
        'is_from_client' => 'boolean',
        'media' => 'array',
        'sent_at' => 'datetime'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
