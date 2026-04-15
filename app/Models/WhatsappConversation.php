<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WhatsappConversation extends Model
{
    use HasFactory;

    // Bucket constants
    const BUCKET_ATTENTION = 'requires_attention';
    const BUCKET_FOLLOW_UP = 'follow_up';
    const BUCKET_CLOSED    = 'closed';

    protected $fillable = [
        'client_id',
        'agent_id',
        'shop_id',
        'status',
        'conversation_bucket',
        'last_message_at',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function messages()
    {
        return $this->hasMany(WhatsappMessage::class, 'client_id', 'client_id');
    }
}
