<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de las acciones importantes de la conciliación (documento de Fran del 2026-10-06, §28): usuario, fecha y
 * hora, acción y pago o extracto afectado.
 */
class ReconciliationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'reconciliation_item_id', 'bank_statement_id', 'data', 'note'];

    protected $casts = ['data' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function log(string $action, ?int $userId, array $attributes = []): self
    {
        return self::create(['action' => $action, 'user_id' => $userId] + $attributes);
    }
}
