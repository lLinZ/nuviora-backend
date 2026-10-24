<?php

namespace App\Models;

use App\Constants\LogisticsStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * El paso de una orden por un centro logístico (§5, §20.1): su estado logístico, quién la lleva (rider o agencia) y los
 * tiempos del §26. La orden conserva sus estados de siempre (R3). Cada cambio deja su evento (§26: "no basta con
 * guardar el estado final").
 */
class LogisticsOrder extends Model
{
    protected $fillable = [
        'order_id', 'center_id', 'status', 'rider_id', 'pickup_rider_id', 'agency_id', 'overflow_reason',
        'center_client_seconds', 'center_client_meters', 'center_client_at', 'assigned_via', 'assigned_by', 'assignment_note',
        'released_at', 'sla_started_at', 'searching_started_at', 'rider_confirmed_at', 'ready_at',
        'departed_at', 'delivered_at', 'return_pending_at', 'returned_at', 'review_resolved_at', 'closed_at',
    ];

    protected $casts = [
        'released_at' => 'datetime',
        'sla_started_at' => 'datetime',
        'searching_started_at' => 'datetime',
        'rider_confirmed_at' => 'datetime',
        'ready_at' => 'datetime',
        'departed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'return_pending_at' => 'datetime',
        'returned_at' => 'datetime',
        'review_resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'center_client_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function center()
    {
        return $this->belongsTo(LogisticsCenter::class, 'center_id');
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function rounds()
    {
        return $this->hasMany(DispatchRound::class)->orderBy('number');
    }

    public function offers()
    {
        return $this->hasMany(DispatchOffer::class)->orderBy('id');
    }

    public function novelties()
    {
        return $this->hasMany(LogisticsNovelty::class)->orderBy('id');
    }

    public function custody()
    {
        return $this->hasMany(LogisticsCustodyEvent::class)->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(LogisticsOrderEvent::class)->orderBy('id');
    }

    /** El rider que todavía tiene el paquete mientras se busca reemplazo (§14.2 punto 5). */
    public function pickupRider()
    {
        return $this->belongsTo(User::class, 'pickup_rider_id');
    }

    public function incidents()
    {
        return $this->hasMany(LogisticsIncident::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, LogisticsStatus::FINISHED, true);
    }

    /** Minutos de SLA consumidos (§5: siguen corriendo aunque cambie de rider, entre en novedad o pase a agencia). */
    public function slaMinutes(?\DateTimeInterface $at = null): ?float
    {
        if (!$this->sla_started_at) {
            return null;
        }
        $end = $this->delivered_at ?? $at ?? now();

        return round($this->sla_started_at->diffInSeconds($end) / 60, 1);
    }
}
