<?php

// app/Services/Assignment/LoadBalancedStrategy.php
namespace App\Services\Assignment;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoadBalancedStrategy implements AssignmentStrategy
{
    public function pickAgentId(Collection $agents, Order $order): int
    {
        if ($agents->isEmpty()) throw new \RuntimeException('No hay agentes activos');

        $agentIds = $agents->pluck('id')->all();
        $today = now()->toDateString();

        // contamos órdenes "activas" por agente hoy (ajusta los estados activos según tu negocio)
        $counts = DB::table('orders')
            ->select('agent_id', DB::raw('COUNT(*) as c'))
            ->whereIn('agent_id', $agentIds)
            ->whereDate('created_at', $today)
            ->whereNotIn('status_id', function ($q) {
                $q->select('id')->from('statuses')->whereIn('description', ['Cancelado', 'Entregado', 'Rechazado']);
            })
            ->groupBy('agent_id')
            ->pluck('c', 'agent_id');

        // elegimos el que tenga menos; si no aparece, su count es 0
        $best = $agents->sortBy(fn($a) => $counts[$a->id] ?? 0)->first();

        return $best->id;
    }
}
