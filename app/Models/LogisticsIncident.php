<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una incidencia grave de la ruta (§14): la reporta el rider y la decide el operador. */
class LogisticsIncident extends Model
{
    /** §14.1: "avería, accidente, emergencia, detenido/retardado por autoridad, bloqueo vial, imposibilidad de continuar, otro". */
    public const TYPES = [
        'averia' => 'Avería',
        'accidente' => 'Accidente',
        'emergencia' => 'Emergencia',
        'autoridad' => 'Detenido o retardado por la autoridad',
        'bloqueo_vial' => 'Bloqueo vial',
        'no_puede_continuar' => 'No puede continuar',
        'otro' => 'Otro',
    ];

    public const OPEN = 'abierta';           // esperando la decisión del operador
    public const CONTINUES = 'continua';     // el operador decidió que sigue con la ruta
    public const INTERRUPTED = 'interrumpida'; // ruta interrumpida: se busca reemplazo (§14.2)
    public const CLOSED = 'cerrada';         // el reemplazo recibió el paquete, o la orden salió del flujo

    /** Mientras esté en uno de estos, el rider no recibe rondas. */
    public const ACTIVE = [self::OPEN, self::INTERRUPTED];

    protected $fillable = [
        'logistics_order_id', 'order_id', 'center_id', 'rider_id', 'type', 'comment', 'lat', 'lng', 'reported_at', 'status', 'pickup',
        'interrupted_at', 'interrupted_by', 'replacement_rider_id', 'transferred_at', 'resolved_at', 'resolved_by', 'result',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'reported_at' => 'datetime',
        'interrupted_at' => 'datetime',
        'transferred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function logisticsOrder()
    {
        return $this->belongsTo(LogisticsOrder::class);
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function replacement()
    {
        return $this->belongsTo(User::class, 'replacement_rider_id');
    }
}
