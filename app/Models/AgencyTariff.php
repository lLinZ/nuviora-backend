<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una versión del tarifario de una agencia en una ciudad (Módulo 3 de Fran, §10, §13). Las versiones no se editan:
 * un cambio es una versión nueva, y las carreras ya calculadas conservan la suya (§13, §41).
 */
class AgencyTariff extends Model
{
    protected $fillable = ['agency_id', 'city_id', 'version', 'valid_from', 'valid_to', 'is_active', 'notes', 'created_by'];

    protected $casts = [
        'valid_from' => 'date:Y-m-d',
        'valid_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function ranges()
    {
        return $this->hasMany(AgencyTariffRange::class)->orderBy('position');
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Las rutas que se cotizaron con esta versión. */
    public function routes()
    {
        return $this->hasMany(OrderDeliveryRoute::class, 'agency_tariff_id');
    }

    /** El rango donde cae una distancia (§11: desde < distancia ≤ hasta; el primero incluye el 0). */
    public function rangeFor(int $meters): ?AgencyTariffRange
    {
        return $this->ranges->first(fn (AgencyTariffRange $r) => $r->contains($meters));
    }
}
