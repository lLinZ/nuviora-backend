<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $appends = ['client_id'];

    public function getClientIdAttribute()
    {
        return $this->order ? $this->order->client_id : null;
    }

    protected $fillable = [
        'order_id',
        'message_id',
        'body',
        'is_from_client',
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
}
