<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una ronda de notificación a riders para una orden (§8: R1 y R2, de 90 s cada una). */
class DispatchRound extends Model
{
    protected $fillable = ['logistics_order_id', 'number', 'started_at', 'expires_at', 'ended_at', 'result'];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function offers()
    {
        return $this->hasMany(DispatchOffer::class, 'round_id');
    }

    public function logisticsOrder()
    {
        return $this->belongsTo(LogisticsOrder::class);
    }
}
