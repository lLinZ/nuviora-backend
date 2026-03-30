<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'customer_number',
        'first_name',
        'last_name',
        'phone',
        'email',
        'country_name',
        'country_code',
        'province',
        'city',
        'address1',
        'address2',
        'last_whatsapp_received_at', // 👈 Anchor for Meta's 24-h messaging window
        'last_interaction_at',
        'agent_id',
    ];

    protected $casts = [
        'last_whatsapp_received_at' => 'datetime',
        'last_interaction_at' => 'datetime',
        'agent_id' => 'integer',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Returns true if the Meta 24-hour free-text window is currently open.
     * The window opens when the client sends a message and closes exactly
     * 24 hours later. After that, only approved templates may be sent.
     */
    public function isWhatsappWindowOpen(): bool
    {
        if (!$this->last_whatsapp_received_at) {
            return false;
        }

        return $this->last_whatsapp_received_at->diffInSeconds(now()) < 86400; // 24 * 60 * 60
    }

    public function whatsappMessages()
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function latestWhatsappMessage()
    {
        return $this->hasOne(WhatsappMessage::class)->ofMany('sent_at', 'max');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function latestOrder()
    {
        return $this->hasOne(Order::class)->latestOfMany();
    }

    public function whatsappConversations()
    {
        return $this->hasMany(WhatsappConversation::class);
    }
}
