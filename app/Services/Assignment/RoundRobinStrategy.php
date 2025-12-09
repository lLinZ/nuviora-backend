<?php
// app/Services/Assignment/RoundRobinStrategy.php
namespace App\Services\Assignment;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Collection;

class RoundRobinStrategy implements AssignmentStrategy
{
    public function pickAgentId(Collection $agents, Order $order): int
    {
        if ($agents->isEmpty()) throw new \RuntimeException('No hay agentes activos');

        // ordenamos por id para tener consistencia
        $sorted = $agents->sortBy('id')->values();

        $last = Setting::get('round_robin_pointer', null); // puede ser agent_id
        $idx = 0;

        if ($last) {
            $pos = $sorted->search(fn($a) => $a->id == $last);
            $idx = $pos === false ? 0 : ($pos + 1) % $sorted->count();
        }

        $picked = $sorted[$idx];
        // actualizamos el puntero
        Setting::set('round_robin_pointer', (string) $picked->id);

        return $picked->id;
    }
}
