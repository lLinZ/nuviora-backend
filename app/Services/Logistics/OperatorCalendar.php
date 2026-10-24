<?php

namespace App\Services\Logistics;

use App\Models\LogisticsCenter;
use App\Models\LogisticsShift;
use App\Models\OperatorOverride;
use App\Models\OperatorPattern;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * El calendario de operadores de un centro (§4.1): quién está programado cada día.
 * - Primero el cambio del día, si lo hay (sustitución, vacaciones, ausencia, cambio de último minuto).
 * - Si no, el patrón recurrente que cae en un día trabajado (12/4 u otro, en días corridos, sin reiniciarse los lunes).
 * - Los días que el centro no abre (domingos, días especiales cerrados) no se programan (§4.1: "exclusión automática de
 *   domingos si el centro está cerrado").
 * La hora es la del patrón o, si no tiene, la del centro ese día. El historial junta lo programado con quién abrió y
 * cerró el turno de verdad (logistics_shifts).
 */
class OperatorCalendar
{
    public function __construct(private Centers $centers)
    {
    }

    /** @return array{date: string, user_id: ?int, name: ?string, start: ?string, end: ?string, source: string, reason: ?string, conflict: bool} */
    public function on(LogisticsCenter $center, CarbonInterface $day): array
    {
        $date = $day->toDateString();
        $hours = $this->centers->hoursOn($center, $day);
        $base = ['date' => $date, 'user_id' => null, 'name' => null, 'start' => null, 'end' => null, 'reason' => null, 'conflict' => false];
        if (!$hours) {
            return $base + ['source' => 'cerrado'];
        }
        $override = OperatorOverride::with('user:id,names')->where('center_id', $center->id)->whereDate('date', $date)->first();
        if ($override) {
            return ['date' => $date, 'user_id' => $override->user_id, 'name' => $override->user?->names,
                'start' => $override->user_id ? substr($override->start_time ?? $hours[0], 0, 5) : null,
                'end' => $override->user_id ? substr($override->end_time ?? $hours[1], 0, 5) : null,
                'source' => 'cambio', 'reason' => OperatorOverride::REASONS[$override->reason] ?? $override->reason, 'conflict' => false];
        }
        $matches = OperatorPattern::with('user:id,names')->where('center_id', $center->id)->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (OperatorPattern $p) => $p->worksOn($day))->values();
        if ($matches->isEmpty()) {
            return $base + ['source' => 'sin_programar'];
        }
        $p = $matches->first();

        return ['date' => $date, 'user_id' => $p->user_id, 'name' => $p->user?->names,
            'start' => substr($p->start_time ?? $hours[0], 0, 5), 'end' => substr($p->end_time ?? $hours[1], 0, 5),
            // Dos patrones el mismo día: se toma el primero y se avisa para corregirlo
            'source' => 'patron', 'reason' => null, 'conflict' => $matches->count() > 1];
    }

    /** Los días de un rango con lo programado y, si ya pasaron, quién abrió y cerró el turno de verdad (§4.1). */
    public function range(LogisticsCenter $center, string $from, string $to): array
    {
        $shifts = LogisticsShift::with(['user:id,names', 'closer:id,names'])->where('center_id', $center->id)
            ->whereBetween('started_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('started_at')->get()
            ->groupBy(fn (LogisticsShift $s) => $s->started_at->toDateString());
        $days = [];
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $days[] = $this->on($center, $d) + [
                'actual' => ($shifts[$d->toDateString()] ?? collect())->map(fn (LogisticsShift $s) => [
                    'opened_by' => $s->user?->names,
                    'started_at' => $s->started_at,
                    'ended_at' => $s->ended_at,
                    'close_type' => $s->close_type,
                ])->values()->all(),
            ];
        }

        return $days;
    }
}
