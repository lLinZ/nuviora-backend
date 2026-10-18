<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ruta logística (Módulo 5 de Fran, §60–65): agrupa almacenes que el chofer atiende juntos, en un orden sugerido. Todos
 * los almacenes de una ruta están conectados entre sí (§62). Los días habituales son opcionales: hoy no hay días fijos y
 * el chofer sale cuando Fran le dice (R6).
 */
class ReplenishmentRoute extends Model
{
    /** Días de la semana, ISO (1 = lunes) */
    public const DAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    protected $fillable = ['name', 'start_city_id', 'lead_time_days', 'usual_days', 'cost_usd', 'is_active', 'notes'];

    protected $casts = [
        'lead_time_days' => 'float',
        'usual_days' => 'array',
        'cost_usd' => 'float',
        'is_active' => 'boolean',
    ];

    public function stops(): HasMany
    {
        return $this->hasMany(ReplenishmentRouteStop::class, 'route_id')->orderBy('position');
    }

    public function startCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'start_city_id');
    }

    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'start_city' => $this->startCity?->name,
            'lead_time_days' => $this->lead_time_days,
            'usual_days' => $this->usual_days,
            'cost_usd' => $this->cost_usd,
            'is_active' => $this->is_active,
            'stops' => $this->stops->map(fn ($s) => $s->warehouse?->name)->values()->all(),
            'notes' => $this->notes,
        ];
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_city_id' => $this->start_city_id,
            'start_city' => $this->startCity?->name,
            'lead_time_days' => $this->lead_time_days,
            'usual_days' => $this->usual_days ?? [],
            'cost_usd' => $this->cost_usd,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'stops' => $this->stops->map(fn ($s) => [
                'warehouse_id' => $s->warehouse_id,
                'warehouse' => $s->warehouse?->name,
                'city_id' => $s->warehouse?->city_id,
                'position' => $s->position,
            ])->values()->all(),
        ];
    }
}
