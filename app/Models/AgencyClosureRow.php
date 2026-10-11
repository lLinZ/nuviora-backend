<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila del archivo de la agencia (§25, §46): lo que traía sin tocar (raw_data, §26), los datos normalizados para
 * emparejar, la carrera con la que se emparejó y cómo (§27, §28), y el costo esperado de esa carrera (§29, §41).
 */
class AgencyClosureRow extends Model
{
    public const MATCHED = 'matched';
    public const POSSIBLE = 'possible_match';
    public const UNMATCHED = 'unmatched';
    public const MANUAL = 'manually_matched';

    public const STATUS_LABEL = [
        self::MATCHED => 'Emparejada',
        self::POSSIBLE => 'Posible',
        self::UNMATCHED => 'Sin emparejar',
        self::MANUAL => 'Enlazada a mano',
    ];

    public const METHOD_LABEL = [
        'order_id' => 'Número de orden',
        'phone' => 'Teléfono',
        'name_date' => 'Nombre y fecha',
        'ai' => 'IA',
        'manual' => 'A mano',
    ];

    protected $fillable = [
        'closure_id', 'file_id', 'position', 'raw_data', 'external_order_id', 'order_key', 'repeat_mark', 'client_name',
        'client_name_key', 'phone', 'phone_key', 'zone', 'row_date', 'reported_cost', 'matched_order_id', 'matched_trip_id',
        'delivery_route_id', 'match_method', 'match_status', 'match_note', 'candidate_trip_ids', 'expected_cost',
        'approved_cost', 'is_current',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'repeat_mark' => 'boolean',
        'row_date' => 'date:Y-m-d',
        'reported_cost' => 'float',
        'expected_cost' => 'float',
        'approved_cost' => 'float',
        'candidate_trip_ids' => 'array',
        'is_current' => 'boolean',
    ];

    public function closure()
    {
        return $this->belongsTo(AgencyWeeklyClosure::class, 'closure_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'matched_order_id');
    }

    public function trip()
    {
        return $this->belongsTo(AgencyTrip::class, 'matched_trip_id');
    }

    public function route()
    {
        return $this->belongsTo(OrderDeliveryRoute::class, 'delivery_route_id');
    }

    public function discrepancy()
    {
        return $this->hasOne(AgencyClosureDiscrepancy::class, 'closure_row_id')->where('is_current', true);
    }

    public function isLinked(): bool
    {
        return in_array($this->match_status, [self::MATCHED, self::MANUAL], true) && $this->matched_trip_id !== null;
    }
}
