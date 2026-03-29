<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $fillable = [
        'order_id',
        'client_id',
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

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
