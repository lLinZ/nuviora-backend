<?php

namespace App\Services\Assignment;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\OrderAssignmentLog;
use App\Models\Status;
use App\Services\Assignment\Weighted\EffectiveWeights;
use App\Services\Assignment\Weighted\SmoothWeightedRoundRobin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reasignación en bloque (tarea 8 y Fran, segunda ronda §6): pasa las órdenes de una vendedora a
 * otra, o las reparte entre varias con los mismos % del reparto automático. El estado de cada orden
 * no cambia; solo la vendedora. Cada movimiento queda en el historial de la orden y en
 * order_assignment_logs.
 */
class BulkReassignService
{
    public const STRATEGY = 'BulkReassign';

    /** La Líder pasa un pedido suelto (hallazgo H4, spec §5.1). */
    public const STRATEGY_SINGLE = 'LeaderMove';

    /** Estados en los que la orden todavía está en manos de la vendedora. */
    public const REASSIGNABLE_STATUSES = [
        OrderStatus::NUEVO,
        OrderStatus::ASIGNADO_VENDEDOR,
        OrderStatus::LLAMADO_1,
        OrderStatus::LLAMADO_2,
        OrderStatus::LLAMADO_3,
        OrderStatus::ESPERANDO_UBICACION,
        OrderStatus::PROGRAMADO_MAS_TARDE,
        OrderStatus::PROGRAMADO_OTRO_DIA,
        OrderStatus::REPROGRAMADO_HOY,
        OrderStatus::CAMBIO_UBICACION,
        OrderStatus::NOVEDADES,
        OrderStatus::SIN_STOCK,
    ];

    public function __construct(private WeightedAssigner $assigner)
    {
    }

    /** Estados que se pueden mover, en el orden del flujo (no por ID). */
    public function statuses(): Collection
    {
        $order = array_flip(self::REASSIGNABLE_STATUSES);

        return Status::whereIn('description', self::REASSIGNABLE_STATUSES)
            ->get(['id', 'description'])
            ->sortBy(fn ($s) => $order[$s->description])
            ->values();
    }

    /**
     * Vista previa para mover las órdenes de $fromId: cada estado con cuántas tiene, marcando por
     * defecto los activos (los que cuentan para el máximo).
     *
     * @return array<int, array{id: int, description: string, count: int, default: bool}>
     */
    public function preview(int $fromId): array
    {
        $statuses = $this->statuses();
        $counts = Order::where('agent_id', $fromId)
            ->whereIn('status_id', $statuses->pluck('id'))
            ->groupBy('status_id')
            ->selectRaw('status_id, COUNT(*) as c')
            ->pluck('c', 'status_id');

        return $statuses->map(fn ($s) => [
            'id' => $s->id,
            'description' => $s->description,
            'count' => (int) ($counts[$s->id] ?? 0),
            'default' => in_array($s->description, WeightedAssigner::ACTIVE_STATUSES, true),
        ])->values()->all();
    }

    /**
     * @param  int[]  $toIds      vendedoras destino (la de origen se ignora si viene en la lista).
     * @param  int[]  $statusIds  estados a mover.
     * @return array<int, int>  user_id => órdenes que recibió.
     */
    public function reassign(int $fromId, array $toIds, array $statusIds, ?int $byUserId): array
    {
        $toIds = array_values(array_diff(array_unique(array_map('intval', $toIds)), [$fromId]));
        if ($toIds === [] || $statusIds === []) {
            return [];
        }

        // Mismos % del reparto automático (sin mirar el máximo: es una decisión manual).
        // Si las elegidas no tienen peso (por ejemplo, todas en 0 %), se reparte parejo.
        $weights = array_intersect_key(EffectiveWeights::compute($this->assigner->groupInfo($toIds)), array_flip($toIds));
        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys($toIds, 1.0);
        }

        $orderIds = Order::where('agent_id', $fromId)->whereIn('status_id', $statusIds)->orderBy('id')->pluck('id');

        $result = array_fill_keys($toIds, 0);
        $current = [];
        foreach ($orderIds as $orderId) {
            [$to, $current] = SmoothWeightedRoundRobin::pick($weights, $current);

            $moved = DB::transaction(function () use ($orderId, $fromId, $to, $byUserId) {
                $order = Order::where('id', $orderId)->lockForUpdate()->first();
                if (!$order || (int) $order->agent_id !== $fromId) {
                    return null; // alguien la movió mientras tanto
                }
                $order->agent_id = $to; // el observer registra el cambio y sincroniza el CRM
                $order->save();

                OrderAssignmentLog::create([
                    'order_id' => $order->id,
                    'agent_id' => $to,
                    'strategy' => self::STRATEGY,
                    'assigned_by' => $byUserId,
                    'meta' => ['reason' => 'bulk_reassign', 'from_agent_id' => $fromId],
                ]);

                return $order;
            });

            if ($moved) {
                $result[$to]++;
                event(new \App\Events\OrderUpdated($moved));
            }
        }

        return $result;
    }

    /**
     * Pasa una sola orden de $fromId a $toId sin cambiarle el estado. Devuelve null si mientras tanto
     * alguien ya la movió.
     */
    public function moveOne(int $orderId, int $fromId, int $toId, ?int $byUserId): ?Order
    {
        $moved = DB::transaction(function () use ($orderId, $fromId, $toId, $byUserId) {
            $order = Order::where('id', $orderId)->lockForUpdate()->first();
            if (!$order || (int) $order->agent_id !== $fromId) {
                return null;
            }
            $order->agent_id = $toId; // el observer registra el cambio y sincroniza el CRM
            $order->save();

            OrderAssignmentLog::create([
                'order_id' => $order->id,
                'agent_id' => $toId,
                'strategy' => self::STRATEGY_SINGLE,
                'assigned_by' => $byUserId,
                'meta' => ['reason' => 'leader_move', 'from_agent_id' => $fromId],
            ]);

            return $order;
        });

        if ($moved) {
            event(new \App\Events\OrderUpdated($moved));
        }

        return $moved;
    }
}
