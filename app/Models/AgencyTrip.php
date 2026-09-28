<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una carrera o intento de entrega de una agencia (tarea 5). Ver App\Services\Agencies\AgencyTrips. */
class AgencyTrip extends Model
{
    public const TYPES = ['normal' => 'Entrega', 'reentrega' => 'Re-entrega', 'cambio' => 'Cambio'];
    public const RESULTS = ['entregado' => 'Entregado', 'novedad' => 'Novedad', 'rechazado' => 'Rechazado', 'cancelado' => 'Cancelado', 'otro' => 'Otro'];

    protected $fillable = [
        'order_id', 'agency_id', 'deliverer_id', 'type', 'result', 'result_status_id', 'price_usd',
        'trip_date', 'started_at', 'closed_at', 'earning_id', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'price_usd' => 'float',
        'trip_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function deliverer()
    {
        return $this->belongsTo(User::class, 'deliverer_id');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_name' => $this->order?->name,
            'agency_id' => $this->agency_id,
            'agency_name' => $this->agency?->names,
            'deliverer_name' => $this->deliverer?->names,
            'type' => $this->type,
            'type_label' => self::TYPES[$this->type] ?? $this->type,
            'result' => $this->result,
            'result_label' => $this->result ? (self::RESULTS[$this->result] ?? $this->result) : 'En curso',
            'price_usd' => $this->price_usd,
            'trip_date' => $this->trip_date?->toDateString(),
            'started_at' => $this->started_at?->toDateTimeString(),
            'closed_at' => $this->closed_at?->toDateTimeString(),
            'voided_at' => $this->voided_at?->toDateTimeString(),
            'voided_by' => $this->voider?->names,
            'void_reason' => $this->void_reason,
        ];
    }
}
