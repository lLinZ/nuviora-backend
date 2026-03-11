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
    ];

    protected $casts = [
        'last_whatsapp_received_at' => 'datetime',
    ];

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
}
