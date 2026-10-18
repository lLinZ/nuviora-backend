<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/** Auditoría del inventario (Módulo 5 de Fran, §95): usuario, fecha y hora, acción, valor anterior, valor nuevo y motivo. */
class InventoryAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'reason'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, string $entityType, ?int $entityId, ?array $before, ?array $after, ?string $reason = null): self
    {
        return self::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ]);
    }
}
