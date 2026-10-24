<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsIncident;
use App\Models\LogisticsOrder;
use App\Models\RiderProfile;
use Carbon\CarbonInterface;

/**
 * Qué riders pueden recibir la ronda de una orden y en qué orden (§6.1, §7). Determinista y auditable: cada rider queda
 * con sus números o con el motivo por el que no entró, y eso se guarda en el historial de la orden (§7: "el sistema debe
 * poder explicar por qué un rider fue seleccionado").
 *
 * Elegible (§6.1): activo en el centro, no suspendido, dentro de su franja, sin otra orden en curso, con GPS reciente,
 * con la app lista para recibir la alarma, y con un tiempo proyectado que no pase el límite (55 min):
 *   SLA ya consumido + ETA rider → centro + picking + centro → cliente (§7.1).
 * Orden (§7.2): menor ETA al centro; a igual ETA, el que lleva menos entregas hoy (reparto razonable, nunca a costa del
 * SLA); después, el id.
 */
class Candidates
{
    /** Mientras tenga una de estas, el rider no recibe otra ronda (§6.1; las rutas dobles las arma el operador, §9.3). */
    public const BUSY = [
        LogisticsStatus::RIDER_CONFIRMED, LogisticsStatus::PREPARING, LogisticsStatus::READY,
        LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT,
    ];

    public const REASONS = [
        'inactivo' => 'No está activo en el centro',
        'suspendido' => 'Suspendido por administración',
        'fuera_de_franja' => 'Fuera de su franja',
        'ocupado' => 'Ya tiene una orden en curso',
        'incidencia' => 'Tiene una incidencia de ruta sin cerrar',
        'sin_gps' => 'Sin GPS reciente',
        'sin_alarma' => 'La app no puede recibir la alarma',
        'sin_eta' => 'Google no dio el tiempo al centro',
        'muy_lejos' => 'Demasiado lejos para llegar a tiempo',
        'pasa_limite' => 'No llega dentro del límite',
    ];

    public function __construct(private RiderEta $eta, private LogisticsSettings $settings)
    {
    }

    /**
     * @return array{eligible: array<int, array>, excluded: array<int, array>, center_client: ?array, consumed_seconds: int,
     *               limit_minutes: int, warning: ?string, computed_at: string}
     */
    public function for(LogisticsOrder $row, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $center = $row->center()->with('warehouse')->first();
        $settings = $this->settings->for($center, $at);
        $limitSeconds = (int) $settings['sla_limit_minutes'] * 60;
        $targetSeconds = (int) $settings['sla_target_minutes'] * 60;
        $pickingSeconds = (int) ($settings['picking_minutes'] ?? 0) * 60;
        $gpsMaxAge = (int) $settings['gps_max_age_seconds'];
        $consumed = $row->sla_started_at ? max(0, (int) $row->sla_started_at->diffInSeconds($at)) : 0;

        $profiles = RiderProfile::with('user:id,names')->where('center_id', $center->id)->orderBy('id')->get();
        $busy = LogisticsOrder::whereIn('status', self::BUSY)->whereNotNull('rider_id')->where('id', '!=', $row->id)
            ->pluck('rider_id')->map(fn ($id) => (int) $id)->all();
        // §14: el que tuvo una incidencia (y todavía tiene el paquete, o espera que el operador decida) no recibe rondas
        $withIncident = LogisticsIncident::whereIn('status', LogisticsIncident::ACTIVE)->pluck('rider_id')->map(fn ($id) => (int) $id)->all();
        $deliveredToday = LogisticsOrder::where('status', LogisticsStatus::DELIVERED)->whereNotNull('rider_id')
            ->where('delivered_at', '>=', $at->copy()->startOfDay())
            ->selectRaw('rider_id, count(*) as total')->groupBy('rider_id')->pluck('total', 'rider_id');

        $excluded = [];
        $pending = collect();
        foreach ($profiles as $p) {
            $reason = match (true) {
                !$p->is_active => 'inactivo',
                $p->suspended_at !== null => 'suspendido',
                !Centers::inHours(Centers::hoursIn($p->schedule ?? ($center->opening_hours ?? Centers::DEFAULT_HOURS), $at), $at) => 'fuera_de_franja',
                in_array((int) $p->user_id, $busy, true) => 'ocupado',
                in_array((int) $p->user_id, $withIncident, true) => 'incidencia',
                !$p->hasLocation() || $p->last_location_at->lt($at->copy()->subSeconds($gpsMaxAge)) => 'sin_gps',
                !$p->hasPushTarget() || $p->notifications_enabled === false => 'sin_alarma',
                default => null,
            };
            if ($reason === null && $center->warehouse?->hasCoordinates()) {
                // Descarte barato antes de preguntarle a Google: ni a 60 km/h en línea recta llegaría a tiempo
                $km = RiderEta::straightKm($p->last_lat, $p->last_lng, $center->warehouse->latitude, $center->warehouse->longitude);
                if ($consumed + ($km / 60) * 3600 > $limitSeconds) {
                    $reason = 'muy_lejos';
                }
            }
            if ($reason !== null) {
                $excluded[] = $this->line($p) + ['reason' => $reason, 'reason_label' => self::REASONS[$reason]];
            } else {
                $pending->push($p);
            }
        }

        $warning = $this->eta->refreshToCenter($pending, $center);
        $toClient = $this->eta->centerToClient($row);
        if (!$toClient && $warning === null) {
            $warning = 'La orden no tiene el tiempo centro → cliente (sin coordenadas o sin ruta): el límite se mide sin ese tramo.';
        }

        $eligible = [];
        foreach ($pending as $p) {
            $p->refresh();
            // Un ETA de hace más de 3 minutos (Google no respondió al ponerlo al día) no sirve: no se adivina (§33)
            $fresh = $p->eta_computed_at && $p->eta_computed_at->gte($at->copy()->subSeconds(RiderEta::MAX_AGE_SECONDS * 3));
            if ($p->eta_center_seconds === null || !$fresh) {
                $excluded[] = $this->line($p) + ['reason' => 'sin_eta', 'reason_label' => self::REASONS['sin_eta']];
                continue;
            }
            $projected = $consumed + $p->eta_center_seconds + $pickingSeconds + (int) ($toClient['seconds'] ?? 0);
            $line = $this->line($p) + [
                'eta_center_seconds' => $p->eta_center_seconds,
                'eta_center_meters' => $p->eta_center_meters,
                'center_client_seconds' => $toClient['seconds'] ?? null,
                'consumed_seconds' => $consumed,
                'picking_seconds' => $pickingSeconds,
                'projected_minutes' => round($projected / 60, 1),
                // Llega dentro del objetivo (45 min): la R1 va a todos estos (audio de Fran del 23/10)
                'within_target' => $projected <= $targetSeconds,
                'delivered_today' => (int) ($deliveredToday[$p->user_id] ?? 0),
                'gps_age_seconds' => (int) $p->last_location_at->diffInSeconds($at),
            ];
            if ($projected > $limitSeconds) {
                $excluded[] = $line + ['reason' => 'pasa_limite', 'reason_label' => self::REASONS['pasa_limite']];
                continue;
            }
            $eligible[] = $line;
        }

        usort($eligible, fn ($a, $b) => [$a['eta_center_seconds'], $a['delivered_today'], $a['rider_id']]
            <=> [$b['eta_center_seconds'], $b['delivered_today'], $b['rider_id']]);
        foreach ($eligible as $i => &$line) {
            $line['rank'] = $i + 1;
        }
        unset($line);

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
            'center_client' => $toClient,
            'consumed_seconds' => $consumed,
            'limit_minutes' => (int) $settings['sla_limit_minutes'],
            'warning' => $warning,
            'computed_at' => $at->toIso8601String(),
        ];
    }

    /** Lo deja en el historial de la orden (§26). */
    public function record(LogisticsOrder $row, array $result, ?int $actorId = null, string $action = 'candidatos'): void
    {
        app(LogisticsOrders::class)->event($row, $action, $row->status, $row->status, $actorId, $result['warning'], [
            'elegibles' => array_map(fn ($l) => array_intersect_key($l, array_flip(['rider_id', 'name', 'rank', 'eta_center_seconds', 'projected_minutes', 'within_target', 'delivered_today'])), $result['eligible']),
            'descartados' => array_map(fn ($l) => array_intersect_key($l, array_flip(['rider_id', 'name', 'reason', 'projected_minutes'])), $result['excluded']),
            'consumido_s' => $result['consumed_seconds'],
            'centro_cliente_s' => $result['center_client']['seconds'] ?? null,
        ]);
    }

    private function line(RiderProfile $p): array
    {
        return ['rider_id' => (int) $p->user_id, 'name' => $p->user?->names];
    }
}
