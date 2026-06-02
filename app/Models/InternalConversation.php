<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InternalConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendedor_id',
        'agency_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function vendedor()
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
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
     * ¿Es este usuario uno de los dos participantes del hilo?
     */
    public function hasParticipant($userId): bool
    {
        return (int) $this->vendedor_id === (int) $userId
            || (int) $this->agency_id === (int) $userId;
    }
}
