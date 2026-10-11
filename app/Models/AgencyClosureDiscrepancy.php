<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo que no cuadra en un cierre y cómo se resolvió (§29 a §34, §46). Solo la ve administración (§38).
 * Tipos (§32): importe diferente, fila sin emparejar, caso ambiguo, carrera cobrada dos veces y carrera sin cálculo
 * interno (Google no respondió o no hay tarifario: §42, no se inventa el precio).
 */
class AgencyClosureDiscrepancy extends Model
{
    public const KIND_LABEL = [
        'amount' => 'Importe diferente',
        'unmatched' => 'Sin emparejar',
        'ambiguous' => 'Caso ambiguo',
        'duplicate' => 'Cobrada dos veces',
        'no_expected' => 'Sin cálculo interno',
    ];

    /** §33: las acciones que resuelven el caso (enlazar a mano y la nota no lo resuelven solas). */
    public const RESOLUTION_LABEL = [
        'system' => 'Cálculo del sistema',
        'agency' => 'Monto de la agencia',
        'manual' => 'Monto manual',
        'incident' => 'Incidencia',
    ];

    protected $fillable = [
        'closure_id', 'closure_row_id', 'kind', 'expected_cost', 'reported_cost', 'difference', 'status', 'resolution',
        'approved_cost', 'reason', 'resolved_by', 'resolved_at', 'notes', 'is_current',
    ];

    protected $casts = [
        'expected_cost' => 'float',
        'reported_cost' => 'float',
        'difference' => 'float',
        'approved_cost' => 'float',
        'resolved_at' => 'datetime',
        'notes' => 'array',
        'is_current' => 'boolean',
    ];

    public function closure()
    {
        return $this->belongsTo(AgencyWeeklyClosure::class, 'closure_id');
    }

    public function row()
    {
        return $this->belongsTo(AgencyClosureRow::class, 'closure_row_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
