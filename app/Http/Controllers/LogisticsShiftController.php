<?php

namespace App\Http\Controllers;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\RoadReport;
use App\Models\User;
use App\Services\Logistics\Centers;
use App\Services\Logistics\LogisticsSettings;
use App\Services\Logistics\OperatorCalendar;
use App\Services\Logistics\RoadReports;
use App\Services\Logistics\Shifts;
use App\Services\Logistics\Tower;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lo del operador de turno (logística propia, §4.2, §21, §31.1): ver su centro, iniciar y cerrar el turno, y las órdenes
 * y alertas del centro. El Admin ve y hace lo mismo en cualquier centro. Los permisos se validan aquí, no en la pantalla
 * (§33).
 */
class LogisticsShiftController extends Controller
{
    public function __construct(private Centers $centers, private Shifts $shifts)
    {
    }

    /** Los centros del usuario: los suyos si es operador; todos si es Admin. */
    public function mine()
    {
        $user = Auth::user();
        $centers = Shifts::isAdmin($user)
            ? LogisticsCenter::orderBy('id')->get()
            : LogisticsCenter::whereHas('operators', fn ($q) => $q->where('users.id', $user->id))->orderBy('id')->get();

        return response()->json(['status' => true, 'centers' => $centers->map(fn ($c) => $this->summary($c))]);
    }

    public function show(LogisticsCenter $center)
    {
        $this->authorizeCenter($center);

        return response()->json(['status' => true, 'center' => $this->summary($center)]);
    }

    public function start(LogisticsCenter $center)
    {
        $this->authorizeCenter($center);
        $shift = $this->shifts->start($center, Auth::user());

        return response()->json(['status' => true, 'message' => 'Turno iniciado: el centro está abierto para despacho.', 'shift' => $shift, 'center' => $this->summary($center->fresh())]);
    }

    public function close(Request $request, LogisticsCenter $center)
    {
        $this->authorizeCenter($center);
        $request->validate(['reason' => 'nullable|string|max:1000']);
        $shift = $this->shifts->close($center, Auth::user(), $request->input('reason'));

        return response()->json([
            'status' => true,
            'message' => $shift->close_type === 'limpio' ? 'Turno cerrado sin pendientes.' : 'Turno cerrado con pendientes: quedan a la vista del siguiente operador.',
            'shift' => $shift,
            'center' => $this->summary($center->fresh()),
        ]);
    }

    /** Lo que queda abierto antes de cerrar (§31.1), para mostrarlo en el checklist. */
    public function pending(LogisticsCenter $center)
    {
        $this->authorizeCenter($center);

        return response()->json(['status' => true, 'pending' => $this->shifts->pending($center)]);
    }

    /** Las órdenes del centro que siguen en curso, con su reloj de SLA, en el orden de la cola de prioridad (§5, §21). */
    public function orders(Request $request, LogisticsCenter $center, Tower $tower)
    {
        $this->authorizeCenter($center);
        $rows = $tower->orders($center, $request->boolean('all'));
        $settings = app(LogisticsSettings::class)->for($center);

        return response()->json([
            'status' => true,
            'orders' => $rows,
            'summary' => $tower->summary($rows, $center),
            'settings' => array_intersect_key($settings, array_flip(['sla_target_minutes', 'sla_limit_minutes', 'manual_calls_at_seconds', 'hard_cap_seconds', 'cash_limit_usd'])),
            // §15: lo que reportaron los riders en la vía, mientras siga activo
            'road_reports' => app(RoadReports::class)->active($center)->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** §4.1: quién está programado cada día y, en los que ya pasaron, quién abrió y cerró. ?from=&to= (hasta 62 días). */
    public function calendar(Request $request, LogisticsCenter $center, OperatorCalendar $calendar)
    {
        $this->authorizeCenter($center);
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $data['from'] ?? now()->subDays(7)->toDateString();
        $to = $data['to'] ?? now()->addDays(27)->toDateString();
        if (\Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) > 62) {
            return response()->json(['status' => false, 'message' => 'Elige hasta dos meses.'], 422);
        }

        return response()->json(['status' => true, 'days' => $calendar->range($center, $from, $to)]);
    }

    /** §15: el operador marca una incidencia vial "sigue activo" o "ya despejado". POST { vote } */
    public function voteRoadReport(Request $request, RoadReport $report, RoadReports $reports)
    {
        $this->authorizeCenter($report->center);
        $data = $request->validate(['vote' => 'required|in:activo,despejado']);
        $out = $reports->vote($report, Auth::user(), $data['vote']);

        return response()->json(['status' => $out['ok'], 'message' => $out['message']], $out['code']);
    }

    public function resolveAlert(LogisticsCenter $center, LogisticsAlert $alert)
    {
        $this->authorizeCenter($center);
        abort_unless((int) $alert->center_id === (int) $center->id, 404);
        $alert->update(['resolved_at' => now(), 'resolved_by' => Auth::id()]);

        return response()->json(['status' => true, 'message' => 'Alerta cerrada.']);
    }

    private function summary(LogisticsCenter $center): array
    {
        $center->loadMissing(['city:id,name', 'activeShift.user:id,names']);
        $state = $this->centers->state($center);
        $counts = LogisticsOrder::where('center_id', $center->id)->whereNotIn('status', LogisticsStatus::FINISHED)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'id' => $center->id,
            'name' => $center->name,
            'city' => $center->city?->name,
            'is_active' => $center->is_active,
            'state' => $state,
            'state_label' => Centers::STATE_LABELS[$state],
            'today_hours' => $this->centers->hoursOn($center, now()),
            'dispatch_cutoff' => $center->dispatch_cutoff ? substr($center->dispatch_cutoff, 0, 5) : null,
            'before_cutoff' => $this->centers->beforeCutoff($center),
            'active_shift' => $center->activeShift ? [
                'id' => $center->activeShift->id,
                'user' => $center->activeShift->user?->only(['id', 'names']),
                'started_at' => $center->activeShift->started_at,
            ] : null,
            'counts' => $counts,
            'alerts' => LogisticsAlert::where('center_id', $center->id)->whereNull('resolved_at')->orderByDesc('id')->limit(50)
                ->get(['id', 'type', 'level', 'message', 'order_id', 'rider_id', 'created_at']),
            'last_close' => $center->shifts()->whereNotNull('ended_at')->latest('ended_at')->first(['id', 'user_id', 'ended_at', 'close_type', 'close_reason', 'pending_at_close']),
        ];
    }

    private function authorizeCenter(LogisticsCenter $center): void
    {
        /** @var User $user */
        $user = Auth::user();
        if (!Shifts::isAdmin($user) && !$this->shifts->isOperatorOf($user, $center)) {
            abort(response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403));
        }
    }
}
