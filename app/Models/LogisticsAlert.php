<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una alerta de la torre de control (§21, §25): queda abierta hasta que alguien la resuelve o deja de aplicar. */
class LogisticsAlert extends Model
{
    public const CRITICAL = 'critical';
    public const RED = 'red';
    public const WARNING = 'warning';
    public const INFO = 'info';

    protected $fillable = ['center_id', 'order_id', 'rider_id', 'type', 'level', 'message', 'data', 'dedupe_key', 'resolved_at', 'resolved_by'];

    protected $casts = [
        'data' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function center()
    {
        return $this->belongsTo(LogisticsCenter::class, 'center_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
