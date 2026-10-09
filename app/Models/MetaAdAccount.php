<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cuenta publicitaria de Meta (Módulo 2, §5 y §6). Se sincroniza solo si el Admin la activó; desactivarla deja de
 * sincronizarla sin borrar nada ni tocarla dentro de Meta (§5). La moneda, la zona horaria y el estado vienen de Meta.
 */
class MetaAdAccount extends Model
{
    protected $fillable = [
        'meta_connection_id', 'meta_id', 'name', 'currency', 'timezone_name', 'account_status', 'is_active',
        'activated_at', 'history_from', 'backfill_from', 'presets_synced_at', 'first_synced_at', 'last_synced_at',
        'last_seen_at', 'last_error_kind', 'last_error', 'last_error_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'account_status' => 'integer',
        'activated_at' => 'datetime',
        'history_from' => 'date:Y-m-d',
        'backfill_from' => 'date:Y-m-d',
        'presets_synced_at' => 'datetime',
        'first_synced_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function connection()
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    /** El ID tal como lo pide la API: "act_<id>". */
    public function actId(): string
    {
        return 'act_' . $this->meta_id;
    }
}
