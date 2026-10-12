<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La configuración del cierre de cada agencia (Módulo 3 de Fran): el día y la hora del aviso, y si está activo (§21); la
 * tolerancia para las diferencias (§30), que solo ve el Admin; y el mapeo de su archivo (§24). Si la agencia no tiene
 * fila, valen los valores por defecto: miércoles a las 11:00 (R15 del Módulo 4; antes, 8:00), activo y tolerancia 0 (§30: "por ahora no existe un valor
 * fijo").
 */
class AgencyClosureSetting extends Model
{
    public const DAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    protected $fillable = ['agency_id', 'closing_day', 'closing_time', 'reminder_active', 'tolerance_amount', 'tolerance_percent', 'review_distance_km', 'file_mapping', 'updated_by'];

    protected $casts = [
        'closing_day' => 'integer',
        'reminder_active' => 'boolean',
        'tolerance_amount' => 'float',
        'tolerance_percent' => 'float',
        'review_distance_km' => 'float',
        'file_mapping' => 'array',
    ];

    protected $attributes = [
        'closing_day' => 3,
        'closing_time' => '11:00:00', // Fran, Módulo 4, R15 (2026-10-12): a las 11, todas las agencias tienen el sistema abierto
        'reminder_active' => true,
        'tolerance_amount' => 0,
        'review_distance_km' => 25, // Fran, 2026-10-12: "el límite de cada agencia a los pedidos que son más de 25 km"
    ];

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    /** La de la agencia, o una nueva con los valores por defecto (sin guardar). */
    public static function for(int $agencyId): self
    {
        return self::firstOrNew(['agency_id' => $agencyId]);
    }

    /**
     * §29 y §30: si lo que cobra la agencia cuadra con lo calculado. La diferencia permitida es la mayor entre el monto
     * fijo y el porcentaje del cálculo, si hay porcentaje.
     */
    public function withinTolerance(float $expected, float $reported): bool
    {
        $allowed = max((float) $this->tolerance_amount, $this->tolerance_percent ? $expected * (float) $this->tolerance_percent / 100 : 0);

        return abs(round($reported - $expected, 2)) <= round($allowed, 2) + 0.0001;
    }

    /** "08:00" */
    public function timeLabel(): string
    {
        return substr((string) $this->closing_time, 0, 5);
    }
}
