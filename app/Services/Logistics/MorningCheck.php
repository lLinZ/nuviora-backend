<?php

namespace App\Services\Logistics;

use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Models\RiderProfile;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * El chequeo de la mañana (§6: "comprobación de preparación matutina alrededor de las 08:45… mostrar riders esperados,
 * última ubicación y ETA al almacén, y alertar al operador si un rider programado está demasiado lejos"). Corre una vez
 * por día y centro, a la hora de los parámetros. Avisa de los riders del día que no tienen GPS reciente o la alarma lista;
 * "demasiado lejos" solo cuando hay un ETA máximo (Fran todavía no lo fijó: sin valor, no se inventa).
 */
class MorningCheck
{
    public function __construct(private LogisticsSettings $settings, private RiderEta $eta, private Alerts $alerts)
    {
    }

    /** @return array|null  el resultado, o null si todavía no es hora o ya se hizo hoy */
    public function runIfDue(LogisticsCenter $center, ?CarbonInterface $at = null): ?array
    {
        $at ??= now();
        $settings = $this->settings->for($center, $at);
        $when = $settings['morning_check_at'] ?? null;
        $key = "logistics.morning_check.{$center->id}";
        if (!$when || $at->format('H:i') < $when || Setting::get($key) === $at->toDateString()) {
            return null;
        }
        Setting::set($key, $at->toDateString());

        return $this->run($center, $at, $settings);
    }

    public function run(LogisticsCenter $center, CarbonInterface $at, array $settings): array
    {
        $profiles = RiderProfile::with('user:id,names')->where('center_id', $center->id)->where('is_active', true)
            ->whereNull('suspended_at')->get()
            ->filter(fn (RiderProfile $p) => Centers::hoursIn($p->schedule ?? ($center->opening_hours ?? Centers::DEFAULT_HOURS), $at) !== null)
            ->values();
        $warning = $this->eta->refreshToCenter($profiles, $center, 0);
        $gpsMaxAge = (int) $settings['gps_max_age_seconds'];
        $maxEta = $settings['morning_check_max_eta_minutes'] ?? null;
        $date = $at->toDateString();
        $lines = [];

        foreach ($profiles as $p) {
            $p->refresh();
            $name = $p->user?->names ?? "rider #{$p->user_id}";
            $problem = match (true) {
                !$p->hasLocation() || $p->last_location_at->lt($at->copy()->subSeconds($gpsMaxAge)) => ['chequeo_sin_gps', "{$name}: sin ubicación reciente en el chequeo de la mañana."],
                !$p->hasPushTarget() || $p->notifications_enabled === false => ['chequeo_sin_alarma', "{$name}: la app no puede recibir la alarma."],
                $maxEta !== null && $p->eta_center_seconds !== null && $p->eta_center_seconds > $maxEta * 60
                    => ['chequeo_lejos', "{$name}: está a " . round($p->eta_center_seconds / 60) . " min del centro (máximo {$maxEta})."],
                default => null,
            };
            if ($problem) {
                $this->alerts->raise($center, $problem[0], LogisticsAlert::WARNING, $problem[1], "{$problem[0]}:{$center->id}:{$date}:{$p->user_id}", null, (int) $p->user_id);
            }
            $lines[] = [
                'rider_id' => (int) $p->user_id,
                'name' => $name,
                'eta_center_minutes' => $p->eta_center_seconds !== null ? round($p->eta_center_seconds / 60, 1) : null,
                'last_location_at' => $p->last_location_at?->toIso8601String(),
                'problem' => $problem[0] ?? null,
            ];
        }

        return ['riders' => $lines, 'warning' => $warning];
    }
}
