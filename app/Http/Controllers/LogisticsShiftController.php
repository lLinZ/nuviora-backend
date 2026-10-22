<?php

namespace App\Http\Controllers;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\User;
use App\Services\Logistics\Centers;
use App\Services\Logistics\Shifts;
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

    /** Las órdenes del centro que siguen en curso, con su reloj de SLA (§5, §21). */
    public function orders(Request $request, LogisticsCenter $center)
    {
        $this->authorizeCenter($center);
        $rows = LogisticsOrder::with(['order:id,name,order_number,current_total_price,client_id,status_id,location', 'order.client:id,first_name,last_name,phone,address1', 'order.status:id,description', 'rider:id,names', 'agency:id,names'])
            ->where('center_id', $center->id)
            ->when(!$request->boolean('all'), fn ($q) => $q->whereNotIn('status', LogisticsStatus::FINISHED))
            ->orderBy('id')->limit(500)->get();
        $settings = app(\App\Services\Logistics\LogisticsSettings::class)->for($center);

        return response()->json([
            'status' => true,
            'orders' => $rows->map(fn (LogisticsOrder $r) => $r->toArray() + [
                'status_label' => LogisticsStatus::label($r->status),
                'sla_minutes' => $r->slaMinutes(),
                'sla_level' => $this->slaLevel($r->slaMinutes(), $settings),
            ]),
            'settings' => ['sla_target_minutes' => $settings['sla_target_minutes'], 'sla_limit_minutes' => $settings['sla_limit_minutes']],
        ]);
    }

    public function resolveAlert(LogisticsCenter $center, LogisticsAlert $alert)
    {
        $this->authorizeCenter($center);
        abort_unless((int) $alert->center_id === (int) $center->id, 404);
        $alert->update(['resolved_at' => now(), 'resolved_by' => Auth::id()]);

        return response()->json(['status' => true, 'message' => 'Alerta cerrada.']);
    }

    private function slaLevel(?float $minutes, array $settings): ?string
    {
        if ($minutes === null) {
            return null;
        }

        return match (true) {
            $minutes >= $settings['sla_limit_minutes'] => 'vencido',
            $minutes >= $settings['sla_target_minutes'] => 'riesgo',
            default => 'ok',
        };
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
