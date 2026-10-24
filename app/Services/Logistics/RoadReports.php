<?php

namespace App\Services\Logistics;

use App\Models\LogisticsCenter;
use App\Models\RoadReport;
use App\Models\RoadReportVote;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Las incidencias viales compartidas (§15). Un rider reporta lo que ve en la vía con su GPS; caduca sola después de
 * road_report_minutes (§15: "caducidad automática configurable"); otros riders y el operador marcan "sigue activo" (la
 * caducidad vuelve a empezar) o "ya despejado". Solo informa: no se usa para evadir controles de la autoridad (§15) y el
 * ETA sigue saliendo de Google con el tráfico real.
 */
class RoadReports
{
    public function __construct(private LogisticsSettings $settings)
    {
    }

    public function minutes(LogisticsCenter $center): int
    {
        return max(1, (int) ($this->settings->for($center)['road_report_minutes'] ?? 60));
    }

    public function report(LogisticsCenter $center, User $user, string $type, ?string $comment, float $lat, float $lng): RoadReport
    {
        return RoadReport::create([
            'center_id' => $center->id,
            'reported_by' => $user->id,
            'type' => $type,
            'comment' => $comment,
            'lat' => $lat,
            'lng' => $lng,
            'reported_at' => now(),
            'expires_at' => now()->addMinutes($this->minutes($center)),
        ]);
    }

    /** §15: "Otros riders/operador pueden marcar 'sigue activo' o 'ya despejado'". */
    public function vote(RoadReport $report, User $user, string $vote): array
    {
        if (!$report->isActive()) {
            return ['ok' => false, 'message' => 'Ese reporte ya no está activo.', 'code' => 409];
        }
        RoadReportVote::create(['road_report_id' => $report->id, 'user_id' => $user->id, 'vote' => $vote, 'created_at' => now()]);
        if ($vote === RoadReportVote::CLEARED) {
            $report->update(['cleared_at' => now(), 'cleared_by' => $user->id]);

            return ['ok' => true, 'message' => 'Marcado como despejado.', 'code' => 200];
        }
        $minutes = $this->minutes($report->center);
        $report->update(['expires_at' => now()->addMinutes($minutes)]);

        return ['ok' => true, 'message' => "Gracias: queda activo {$minutes} minutos más.", 'code' => 200];
    }

    /** Las activas del centro, la más nueva primero. */
    public function active(LogisticsCenter $center): Collection
    {
        return RoadReport::with('reporter:id,names')->withCount([
            'votes as still_count' => fn ($q) => $q->where('vote', RoadReportVote::STILL),
        ])->where('center_id', $center->id)->whereNull('cleared_at')->where('expires_at', '>', now())
            ->orderByDesc('reported_at')->limit(50)->get()
            ->map(fn (RoadReport $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_label' => $r->typeLabel(),
                'comment' => $r->comment,
                'lat' => $r->lat,
                'lng' => $r->lng,
                'reported_at' => $r->reported_at,
                'expires_at' => $r->expires_at,
                'reporter' => $r->reporter?->names,
                'still_count' => (int) $r->still_count,
            ]);
    }
}
