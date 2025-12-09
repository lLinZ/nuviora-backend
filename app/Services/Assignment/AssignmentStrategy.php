<?php

namespace App\Services\Assignment;

use App\Models\Order;
use Illuminate\Support\Collection;

interface AssignmentStrategy
{
    /**
     * @param Collection<int, \App\Models\User> $agents  Agentes activos hoy
     * @param Order $order  Orden a asignar (puedes usar info como zona, total, etc.)
     * @return int agent_id seleccionado
     */
    public function pickAgentId(Collection $agents, Order $order): int;
}
