<?php
// app/Services/Assignment/AssignOrderService.php
namespace App\Services\Assignment;

use App\Models\DailyAgentRoster;
use App\Models\Order;
use App\Models\OrderAssignmentLog;
use App\Models\Setting;
use App\Models\Status;
use App\Models\BusinessDay;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssignOrderService
{
    protected AssignmentStrategy $strategy;

    public function __construct()
    {
        $mode = Setting::get('assignment_strategy', 'round_robin');
        $this->strategy = $mode === 'load_balanced'
            ? new LoadBalancedStrategy()
            : new RoundRobinStrategy();
    }

    /**
     * Asigna una orden (si hay roster y dentro de horario).
     * @throws \Throwable
     */
    public function assignOne(Order $order): ?User
    {
        if ($order->agent_id) return $order->agent;

        // si quieres bloquear fuera de jornada:
        if (!$this->isBusinessOpen($order->shop_id)) {
            return null; // fuera de jornada, no auto-asigna
        }

        $agents = $this->activeAgentsForDate(now()->toDateString(), $order->shop_id);
        if ($agents->isEmpty()) return null;

        return DB::transaction(function () use ($order, $agents) {
            $ord = Order::where('id', '=', $order->id)->lockForUpdate()->first(['*']);
            if ($ord->agent_id) return $ord->agent;

            $agentId = $this->strategy->pickAgentId($agents, $ord);

            $ord->update(['agent_id' => $agentId]);

            \App\Models\OrderAssignmentLog::create([
                'order_id'    => $ord->id,
                'agent_id'    => $agentId,
                'strategy'    => (new \ReflectionClass($this->strategy))->getShortName(),
                'assigned_by' => null, // sistema
                'meta'        => ['reason' => 'auto'],
            ]);

            return $ord->agent;
        });
    }

    /**
     * Asigna todas las órdenes sin agente en un rango de tiempo.
     * Devuelve cantidad asignada.
     */
    public function assignBacklog(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $date = $to->format('Y-m-d');
        $ids = Order::query()
            ->whereNull('agent_id')
            ->whereBetween('created_at', [$from, $to])
            ->get(['*']); // Fetch full models to get shop_id

        $assignmentStatus = Status::firstOrCreate(['description' => 'Asignado a Vendedor']);
        $assignmentStatusId = (int)$assignmentStatus->id;

        $count = 0;
        foreach ($ids as $ordModel) {
            $agentsForShop = $this->activeAgentsForDate($date, $ordModel->shop_id);
            if ($agentsForShop->isEmpty()) continue;

            DB::transaction(function () use ($ordModel, $agentsForShop, &$count, $assignmentStatusId) {
                $ord = Order::where('id', '=', $ordModel->id)->lockForUpdate()->first(['*']);
                if ($ord->agent_id) return;

                $agentId = $this->strategy->pickAgentId($agentsForShop, $ord);
                $ord->update(['agent_id' => $agentId, 'status_id' => $assignmentStatusId]);

                OrderAssignmentLog::create([
                    'order_id'    => $ord->id,
                    'agent_id'    => $agentId,
                    'strategy'    => (new \ReflectionClass($this->strategy))->getShortName(),
                    'assigned_by' => Auth::id(), // lo dispara la gerente desde la UI
                    'meta'        => ['reason' => 'backlog'],
                ]);

                $count++;
            });
        }

        return $count;
    }

    /** Helpers */

    protected function activeAgentsForDate(string $date, ?int $shopId = null): Collection
    {
        $query = DailyAgentRoster::with('agent')
            ->where('date', $date)
            ->where('is_active', true);

        if ($shopId) {
            $query->where('shop_id', $shopId);
        }

        $rows = $query->get();

        return $rows->pluck('agent')->filter();
    }

    protected function isWithinWindow($now, string $open, string $close): bool
    {
        // Soporta casos "normales" (09:00–18:00) y "nocturnos" (20:00–06:00)
        $start = $now->copy()->setTimeFromTimeString($open);
        $end   = $now->copy()->setTimeFromTimeString($close);

        // Si cierra al día siguiente (overnight)
        if ($end->lessThanOrEqualTo($start)) {
            // Ventana: [start, 23:59:59] ∪ [00:00, end]
            return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
        }

        return $now->betweenIncluded($start, $end);
    }


    protected function isBusinessOpen(?int $shopId = null): bool
    {
        $query = BusinessDay::where('date', '=', now()->toDateString());
        if ($shopId) {
            $query->where('shop_id', '=', $shopId);
        }
        $day = $query->first(['*']);
        return $day && $day->open_at && is_null($day->close_at);
    }
}
