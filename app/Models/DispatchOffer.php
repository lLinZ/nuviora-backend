<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La oportunidad de un rider en una ronda (§8). No lleva nada del destino: el rider confirma sin saber zona, cliente,
 * km ni pago (§8, §8.1). Gana el primero que confirma (§8.2).
 */
class DispatchOffer extends Model
{
    public const CONFIRMED = 'confirmada';
    public const UNCONFIRMED = 'sin_confirmar';
    public const COVERED = 'cubierta';
    public const CANCELLED = 'cancelada';

    protected $fillable = [
        'round_id', 'logistics_order_id', 'rider_id', 'rank', 'eta_center_seconds', 'projected_minutes',
        'sent_at', 'expires_at', 'push_ok', 'push_error', 'seen_at', 'responded_at', 'response',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'seen_at' => 'datetime',
        'responded_at' => 'datetime',
        'push_ok' => 'boolean',
        'projected_minutes' => 'float',
    ];

    public function round()
    {
        return $this->belongsTo(DispatchRound::class, 'round_id');
    }

    public function logisticsOrder()
    {
        return $this->belongsTo(LogisticsOrder::class);
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function isOpen(): bool
    {
        return $this->response === null && $this->expires_at->isFuture();
    }
}
