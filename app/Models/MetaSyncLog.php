<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una sincronización con Meta (Módulo 2, §49: conexión, inicio, final, estado, error y registros procesados). */
class MetaSyncLog extends Model
{
    protected $fillable = [
        'meta_connection_id', 'kind', 'requested_by', 'started_at', 'finished_at', 'status', 'error_kind', 'error',
        'records', 'calls', 'details',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'details' => 'array',
    ];

    public function connection()
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
