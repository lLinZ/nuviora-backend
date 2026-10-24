<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un turno de operador en un centro (§4.2: "Iniciar turno" abre el centro para despacho; §31.1: el cierre). */
class LogisticsShift extends Model
{
    public const CLOSE_CLEAN = 'limpio';
    public const CLOSE_WITH_PENDING = 'con_pendientes';

    protected $fillable = ['center_id', 'user_id', 'scheduled_user_id', 'started_at', 'ended_at', 'closed_by', 'close_type', 'close_reason', 'pending_at_close'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'pending_at_close' => 'array',
    ];

    public function center()
    {
        return $this->belongsTo(LogisticsCenter::class, 'center_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Quién estaba programado cuando se abrió (§4.1). */
    public function scheduledUser()
    {
        return $this->belongsTo(User::class, 'scheduled_user_id');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
