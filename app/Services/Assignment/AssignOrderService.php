<?php
// app/Services/Assignment/AssignOrderService.php
namespace App\Services\Assignment;

use App\Models\DailyAgentRoster;
use App\Models\Order;
use App\Models\OrderAssignmentLog;
use App\Models\Setting;
use App\Models\Status;
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
        if ($order->agent_id) return $order->agent; // ya asignada

        $now = now();
        $open  = Setting::get('business_open_at', '09:00');
        $close = Setting::get('business_close_at', '18:00');

        $inWindow = $this->isWithinWindow($now, $open, $close);
        // Para backlog también lo usamos sin ventana; aquí se puede forzar
        $agents = $this->activeAgentsForDate($now->toDateString());

        if ($agents->isEmpty()) return null;

        return DB::transaction(function () use ($order, $agents) {
            // lock de la orden para evitar carreras si múltiples workers
            $ord = Order::where('id', $order->id)->lockForUpdate()->first();
            if ($ord->agent_id) return $ord->agent;

            // pick según estrategia
            $agentId = $this->strategy->pickAgentId($agents, $ord);

            $ord->update(['agent_id' => $agentId]);

            OrderAssignmentLog::create([
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
        $agents = $this->activeAgentsForDate($date);
        if ($agents->isEmpty()) return 0;

        $ids = Order::query()
            ->whereNull('agent_id')
            ->whereBetween('created_at', [$from, $to])
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $agents, &$count) {
                $ord = Order::where('id', $id)->lockForUpdate()->first();
                if ($ord->agent_id) return;

                $agentId = $this->strategy->pickAgentId($agents, $ord);
                $ord->update(['agent_id' => $agentId]);

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

    protected function activeAgentsForDate(string $date): Collection
    {
        $rows = DailyAgentRoster::with('agent')
            ->where('date', $date)
            ->where('is_active', true)
            ->get();

        return $rows->pluck('agent')->filter();
    }

    protected function isWithinWindow($now, string $open, string $close): bool
    {
        $start = $now->copy()->setTimeFromTimeString($open);
        $end   = $now->copy()->setTimeFromTimeString($close);
        return $now->betweenIncluded($start, $end);
    }
}
