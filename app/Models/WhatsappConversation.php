<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WhatsappConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'agent_id',
        'shop_id',
        'status',
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
}
