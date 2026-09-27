<?php

namespace App\Services\SalesGroups;

use App\Models\OrderAssignmentLog;
use App\Models\SalesGroup;
use App\Models\SalesGroupMember;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\Assignment\BulkReassignService;
use Illuminate\Support\Carbon;

/**
 * Arma la parte automática del reporte semanal de la Líder (spec §16.2): cerca del 80 % del reporte sale
 * de lo que el sistema ya registró. La Líder completa solo lo cualitativo (§16.3, WeeklyReport).
 * Semanas de lunes a domingo. Los números usan las mismas reglas que "Mi grupo".
 */
final class WeeklyReportBuilder
{
    public function __construct(private GroupMetrics $metrics, private SaturationMonitor $saturation)
    {
    }

    public function build(SalesGroup $group, Carbon $weekStart): array
    {
        $start = $weekStart->copy()->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);
        $prevStart = $start->copy()->subWeek();
        $prevEnd = $end->copy()->subWeek();

        $members = $group->openMembers()->with('user:id,names,surnames')->get()->keyBy('user_id');
        $ids = $members->keys()->all();
        $name = fn ($id) => trim(($members[$id]->user->names ?? '') . ' ' . ($members[$id]->user->surnames ?? ''));

        $current = $this->metrics->period($ids, $start->toDateString(), $end->toDateString());
        $previous = $this->metrics->period($ids, $prevStart->toDateString(), $prevEnd->toDateString());
        $load = $this->saturation->groupStatus($group);

        $rows = collect($current['rows'])->map(fn ($row, $id) => [
            'user_id' => $id,
            'name' => $name($id),
            'is_leader' => $members[$id]->role === SalesGroupMember::ROLE_LEADER,
            'load' => $load['members'][$id]['load'] ?? 0,
            'previous_effectiveness' => $previous['rows'][$id]['effectiveness'] ?? null,
        ] + $row)->sortByDesc('effectiveness')->values();

        $saved = WeeklyReport::with('editor:id,names,surnames')
            ->where('sales_group_id', $group->id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        return [
            'group' => ['id' => $group->id, 'name' => $group->name],
            'leader' => ($leader = $members->firstWhere('role', SalesGroupMember::ROLE_LEADER)) ? $name($leader->user_id) : null,
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'previous' => ['start' => $prevStart->toDateString(), 'end' => $prevEnd->toDateString(), 'totals' => $previous['totals']],
            'totals' => $current['totals'],
            'rows' => $rows,
            'load_average' => $load['average'],
            'agencies' => $this->metrics->agencies($ids, $start->toDateString(), $end->toDateString()),
            'redistributions' => $this->redistributions($ids, $start, $end),
            'fields' => collect(WeeklyReport::FIELDS)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'value' => $saved?->{$key},
            ])->values(),
            'saved_at' => $saved?->updated_at?->toIso8601String(),
            'saved_by' => $saved?->editor ? trim($saved->editor->names . ' ' . $saved->editor->surnames) : null,
        ];
    }

    /** Pedidos pasados en bloque entre vendedoras del grupo en la semana: de quién, a quién y cuántos. */
    private function redistributions(array $ids, Carbon $start, Carbon $end): array
    {
        $logs = OrderAssignmentLog::where('strategy', BulkReassignService::STRATEGY)
            ->whereIn('agent_id', $ids)
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['agent_id', 'assigned_by', 'meta', 'created_at']);

        $users = User::whereIn('id', $logs->pluck('agent_id')
            ->merge($logs->pluck('assigned_by'))
            ->merge($logs->map(fn ($l) => $l->meta['from_agent_id'] ?? null))
            ->filter()->unique())
            ->get(['id', 'names', 'surnames'])->keyBy('id');
        $label = fn ($id) => $id && $users->has($id) ? trim($users[$id]->names . ' ' . $users[$id]->surnames) : '—';

        return $logs->groupBy(fn ($l) => ($l->meta['from_agent_id'] ?? 0) . ':' . $l->agent_id . ':' . $l->assigned_by)
            ->map(fn ($g) => [
                'from' => $label($g->first()->meta['from_agent_id'] ?? null),
                'to' => $label($g->first()->agent_id),
                'by' => $label($g->first()->assigned_by),
                'orders' => $g->count(),
                'last_at' => $g->max('created_at')?->toIso8601String(),
            ])
            ->sortByDesc('orders')->values()->all();
    }
}
