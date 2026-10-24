<?php

namespace App\Services\Logistics;

use App\Models\LogisticsCenter;
use App\Models\LogisticsSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Los parámetros de la logística propia (§31), con versión y fecha de vigencia (§33). Un centro puede tener los suyos;
 * si no, usa los generales. Ningún valor vive en el código fuera de DEFAULTS, que es la primera versión (§30: "NO
 * hardcodear… tarifario"; §0: lo CONFIGURABLE se edita sin tocar código).
 */
class LogisticsSettings
{
    /**
     * Valores iniciales. Del §31 salvo donde Fran respondió (R7, 21/10). null = el documento lo deja pendiente y Fran no
     * lo fijó: no se inventa (§0, §34.2).
     */
    public const DEFAULTS = [
        'sla_target_minutes' => 45,          // §1.1, §31
        'sla_limit_minutes' => 55,           // §1.1: límite de planificación de la flota propia
        'round1_seconds' => 90,              // §8
        'round1_candidates' => 2,            // mínimo: la R1 va a todos los que llegan en 45 min (Fran, 23/10)
        'round2_seconds' => 90,
        'round2_new_candidates' => 2,        // §8: R2 = los de R1 + hasta 2 nuevos
        'manual_calls_at_seconds' => 180,    // §8: T=3:00, alerta roja y llamadas
        'hard_cap_seconds' => 300,           // §8: T=5:00, pasa a la agencia (R4)
        'max_stops_normal' => 2,             // §10
        'max_stops_exceptional' => 3,
        'cash_limit_usd' => 60,              // §17
        'service_minutes_per_stop' => 10,    // R7 (la propuesta era 5)
        'picking_minutes' => null,           // §7.1: "tiempo de picking/salida configurado"; sin valor de Fran
        'pay_failed_leg' => true,            // R7: si el rider llegó y el cliente no recibió, se paga la ida
        'return_deadline' => 'fin_de_turno', // R7: el mismo día, antes del cierre del turno
        'gps_max_age_seconds' => 300,        // §6.1: "GPS reciente suficiente". Decisión nuestra; confirmar con Fran
        'morning_check_at' => '08:45',       // §6
        'morning_check_max_eta_minutes' => null, // §6: "ETA máximo… configurable"; sin valor de Fran
        'road_report_minutes' => 60,         // §15: "caducidad automática configurable". Decisión nuestra; confirmar con Fran
    ];

    public const RULES = [
        'sla_target_minutes' => 'integer|min:5|max:240',
        'sla_limit_minutes' => 'integer|min:5|max:300',
        'round1_seconds' => 'integer|min:15|max:600',
        'round1_candidates' => 'integer|min:1|max:20',
        'round2_seconds' => 'integer|min:15|max:600',
        'round2_new_candidates' => 'integer|min:0|max:20',
        'manual_calls_at_seconds' => 'integer|min:30|max:1800',
        'hard_cap_seconds' => 'integer|min:60|max:3600',
        'max_stops_normal' => 'integer|min:1|max:5',
        'max_stops_exceptional' => 'integer|min:1|max:5',
        'cash_limit_usd' => 'numeric|min:0|max:10000',
        'service_minutes_per_stop' => 'integer|min:0|max:60',
        'picking_minutes' => 'nullable|integer|min:0|max:120',
        'pay_failed_leg' => 'boolean',
        'return_deadline' => 'string|in:fin_de_turno',
        'gps_max_age_seconds' => 'integer|min:30|max:3600',
        'morning_check_at' => 'nullable|date_format:H:i',
        'morning_check_max_eta_minutes' => 'nullable|integer|min:1|max:240',
        'road_report_minutes' => 'integer|min:5|max:720',
    ];

    /** Los valores vigentes para un centro en un momento: la última versión del centro o, si no tiene, la general. */
    public function for(?LogisticsCenter $center = null, ?CarbonInterface $at = null): array
    {
        $version = $this->version($center, $at);

        return array_merge(self::DEFAULTS, $version?->values ?? []);
    }

    public function version(?LogisticsCenter $center = null, ?CarbonInterface $at = null): ?LogisticsSetting
    {
        $at ??= now();
        $query = fn ($centerId) => LogisticsSetting::where('valid_from', '<=', $at)
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId), fn ($q) => $q->whereNull('center_id'))
            ->orderByDesc('valid_from')->orderByDesc('id')->first();

        return ($center ? $query($center->id) : null) ?? $query(null);
    }

    /**
     * Guarda una versión nueva, vigente desde ahora, con todos los valores (los que no vienen, como estaban). La anterior
     * queda para la auditoría (§33).
     */
    public function update(?LogisticsCenter $center, array $values, ?User $by): LogisticsSetting
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => 'Parámetros desconocidos: ' . implode(', ', $unknown)]);
        }
        validator($values, array_intersect_key(self::RULES, $values))->validate();

        $merged = array_merge($this->for($center), $values);
        if ($merged['max_stops_exceptional'] < $merged['max_stops_normal']) {
            throw ValidationException::withMessages(['max_stops_exceptional' => 'El máximo excepcional no puede ser menor que el normal (§10).']);
        }
        if ($merged['sla_limit_minutes'] < $merged['sla_target_minutes']) {
            throw ValidationException::withMessages(['sla_limit_minutes' => 'El límite no puede ser menor que el objetivo (§1.1).']);
        }
        if ($merged['hard_cap_seconds'] < $merged['manual_calls_at_seconds']) {
            throw ValidationException::withMessages(['hard_cap_seconds' => 'El paso a la agencia no puede ser antes de las llamadas (§8).']);
        }

        return LogisticsSetting::create([
            'center_id' => $center?->id,
            'values' => $merged,
            'valid_from' => now(),
            'created_by' => $by?->id,
        ]);
    }
}
