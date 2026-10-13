<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría financiera (Módulo 4 de Fran, §82): usuario, fecha y hora, valor anterior, valor nuevo y motivo, para gastos,
 * tasas, CPA, saldos, pagos, cierres y configuraciones; y los cambios de una cohorte ya madura (§21). No se borra.
 */
class FinancialAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['action', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'user_id'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, string $subjectType, ?int $subjectId, ?array $before, ?array $after,
        ?string $reason = null, ?int $userId = null): self
    {
        return self::create([
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'user_id' => $userId ?? auth()->id(),
        ]);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'user' => $this->user?->names,
            'at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
