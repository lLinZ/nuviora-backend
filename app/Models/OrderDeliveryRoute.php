<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La ruta almacén → cliente de una orden y su costo esperado (Módulo 3 de Fran, §2, §15, §46). Solo la ve
 * administración (§14). Una orden puede tener varias: la activa es la de hoy, y las anteriores quedan con las carreras
 * que las usaron (§18: almacén → A y almacén → B).
 */
class OrderDeliveryRoute extends Model
{
    public const PENDING = 'pending';                       // falta consultar a Google
    public const CALCULATED = 'calculated';
    public const ERROR = 'error';                           // Google falló (§42): se reintenta
    public const NO_WAREHOUSE = 'no_warehouse';             // la orden no tiene almacén
    public const WAREHOUSE_NO_COORDS = 'warehouse_no_coords';
    public const LOCATION_PENDING = 'location_pending';     // la ubicación del cliente no se pudo leer todavía
    public const NO_TARIFF = 'no_tariff';                   // hay distancia, pero no hay tarifario vigente
    public const OUT_OF_RANGE = 'out_of_range';             // la distancia pasa el último rango
    public const DISTANCE_REVIEW = 'distance_review';       // pasa el límite de km de su agencia: el cierre no la cuenta

    public const STATUS_LABEL = [
        self::PENDING => 'Calculando',
        self::CALCULATED => 'Calculada',
        self::ERROR => 'Error de ruta',
        self::NO_WAREHOUSE => 'Sin almacén',
        self::WAREHOUSE_NO_COORDS => 'Almacén sin coordenadas',
        self::LOCATION_PENDING => 'Ubicación sin resolver',
        self::NO_TARIFF => 'Sin tarifario',
        self::OUT_OF_RANGE => 'Fuera de los rangos',
        self::DISTANCE_REVIEW => 'Distancia por revisar',
    ];

    protected $fillable = [
        'order_id', 'agency_id', 'warehouse_id', 'city_id', 'origin_lat', 'origin_lng', 'destination_lat', 'destination_lng',
        'maps_url', 'distance_meters', 'distance_km', 'tariff_date', 'agency_tariff_id', 'agency_tariff_range_id',
        'expected_cost', 'calculation_status', 'error', 'attempts', 'last_attempt_at', 'calculated_at', 'is_active',
        'superseded_at', 'reason', 'requires_review', 'review_note', 'distance_approved_by', 'distance_approved_at', 'created_by',
    ];

    protected $casts = [
        'origin_lat' => 'float',
        'origin_lng' => 'float',
        'destination_lat' => 'float',
        'destination_lng' => 'float',
        'distance_km' => 'float',
        'expected_cost' => 'float',
        'tariff_date' => 'date:Y-m-d',
        'last_attempt_at' => 'datetime',
        'calculated_at' => 'datetime',
        'superseded_at' => 'datetime',
        'distance_approved_at' => 'datetime',
        'is_active' => 'boolean',
        'requires_review' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function tariff()
    {
        return $this->belongsTo(AgencyTariff::class, 'agency_tariff_id');
    }

    public function range()
    {
        return $this->belongsTo(AgencyTariffRange::class, 'agency_tariff_range_id');
    }

    public function trips()
    {
        return $this->hasMany(AgencyTrip::class, 'delivery_route_id');
    }

    /** Ya tiene distancia de Google (calculada, o sin tarifario o fuera de rango). */
    public function hasDistance(): bool
    {
        return $this->distance_meters !== null;
    }
}
