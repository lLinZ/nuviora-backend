<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Las acciones sensibles del control de tarifas de delivery (Módulo 3 de Fran, §40): quién, qué, el valor de antes y el
 * de después. No se borra.
 */
class DeliveryAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['action', 'subject_type', 'subject_id', 'before', 'after', 'user_id'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, string $subjectType, ?int $subjectId, ?array $before, ?array $after, ?int $userId = null): self
    {
        return self::create([
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'user_id' => $userId ?? auth()->id(),
        ]);
    }
}
