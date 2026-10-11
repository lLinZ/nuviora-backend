<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un rango de un tarifario (Módulo 3 de Fran, §11): desde < km ≤ hasta, con su precio. Puede sumar además un precio por
 * cada km por encima de su "desde" (Fran, 2026-10-09: en PYP, desde 20 km, "cada kilómetro extra cuesta 0.20, es decir,
 * si es 21 kilómetros serían 20 kilómetros y un kilómetro extra"). El último puede no tener "hasta" (en adelante).
 */
class AgencyTariffRange extends Model
{
    protected $fillable = ['agency_tariff_id', 'position', 'min_km', 'max_km', 'price', 'price_per_km'];

    protected $casts = [
        'min_km' => 'float',
        'max_km' => 'float',
        'price' => 'float',
        'price_per_km' => 'float',
    ];

    public function tariff()
    {
        return $this->belongsTo(AgencyTariff::class, 'agency_tariff_id');
    }

    /**
     * Se compara en metros, con la distancia real sin redondear (§9: 5,001 km cae en el rango siguiente). El primer
     * rango (desde 0) también incluye el 0.
     */
    public function contains(int $meters): bool
    {
        $min = (int) round($this->min_km * 1000);
        $above = $min === 0 ? $meters >= 0 : $meters > $min;

        return $above && ($this->max_km === null || $meters <= (int) round($this->max_km * 1000));
    }

    /**
     * Precio de la carrera: el del rango, más el precio por km extra sobre la distancia exacta por encima de "desde"
     * (21,5 km en PYP = $4 + 1,5 × $0,20 = $4,30).
     */
    public function priceFor(int $meters): float
    {
        $extraKm = max(0, $meters - (int) round($this->min_km * 1000)) / 1000;

        return round((float) $this->price + ($this->price_per_km !== null ? $extraKm * $this->price_per_km : 0), 2);
    }

    public function label(): string
    {
        $fmt = fn (?float $v) => rtrim(rtrim(number_format((float) $v, 3, ',', ''), '0'), ',');

        if ($this->max_km === null) {
            return $this->min_km == 0 ? 'cualquier distancia' : "más de {$fmt($this->min_km)} km";
        }
        return "{$fmt($this->min_km)}–{$fmt($this->max_km)} km";
    }
}
