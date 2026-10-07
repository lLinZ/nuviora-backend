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

    protected $appends = ['is_whatsapp_window_open'];

    public function getIsWhatsappWindowOpenAttribute()
    {
        return $this->isWhatsappWindowOpen();
    }

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

    /** Largo máximo de la dirección como variable de una plantilla de WhatsApp. */
    public const ADDRESS_MAX = 250;

    /**
     * La dirección que el cliente escribió en el formulario, lista para la variable {{n}} de una plantilla.
     * Fran (2026-10-06): el formulario no toma GPS, así que los mensajes llevan siempre esta, nunca el enlace.
     * Dirección, punto de referencia y estado, sin repetir lo que ya está. Meta rechaza la plantilla entera si
     * la variable trae saltos de línea, tabuladores o más de 4 espacios seguidos: por eso se juntan los espacios.
     */
    public function writtenAddress(): ?string
    {
        // ¿$text ya trae $piece como palabras completas? ("Santa Clara" no trae el estado "Lara")
        $has = fn (string $text, string $piece) => (bool) preg_match(
            '/(?<![\pL\pN])' . preg_quote(mb_strtolower($piece), '/') . '(?![\pL\pN])/u', mb_strtolower($text)
        );

        $parts = [];
        foreach ([$this->address1, $this->address2, $this->city ?: $this->province] as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', (string) $part), " ,.;");
            if ($part === '') continue;

            if (collect($parts)->contains(fn ($p) => $has($p, $part))) continue;
            // Si la nueva parte ya incluye una anterior (la referencia repite la dirección), queda solo la nueva
            $parts = array_values(array_filter($parts, fn ($p) => !$has($part, $p)));
            $parts[] = $part;
        }

        return $parts ? mb_strimwidth(implode(', ', $parts), 0, self::ADDRESS_MAX, '…') : null;
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

    public function latestWhatsappConversation()
    {
        return $this->hasOne(WhatsappConversation::class)->latestOfMany();
    }

    public function activeWhatsappConversation()
    {
        return $this->hasOne(WhatsappConversation::class)->where('status', 'open')->latestOfMany();
    }
}
