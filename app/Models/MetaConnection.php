<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una conexión con Meta (documento de Fran del 2026-10-08, Módulo 2, §3): un Business Manager, System User o token, con
 * las cuentas publicitarias que trae. Puede haber varias a la vez, y ningún token se da por válido para todos los BM.
 *
 * El token y el App Secret van cifrados con la APP_KEY y nunca salen hacia el navegador (§4): están ocultos al
 * serializar, y la API solo informa si hay uno guardado.
 */
class MetaConnection extends Model
{
    protected $fillable = [
        'name', 'business_id', 'access_token', 'app_secret', 'meta_user_id', 'meta_user_name', 'scopes', 'status',
        'available_accounts', 'accounts_checked_at', 'last_success_at', 'last_error_kind', 'last_error',
        'last_error_at', 'created_by',
    ];

    protected $hidden = ['access_token', 'app_secret'];

    protected $casts = [
        'access_token' => 'encrypted',
        'app_secret' => 'encrypted',
        'scopes' => 'array',
        'available_accounts' => 'array',
        'accounts_checked_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function adAccounts()
    {
        return $this->hasMany(MetaAdAccount::class);
    }

    public function syncLogs()
    {
        return $this->hasMany(MetaSyncLog::class);
    }
}
