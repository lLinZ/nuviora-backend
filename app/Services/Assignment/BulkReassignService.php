<?php

namespace App\Services\Assignment;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\OrderAssignmentLog;
use App\Services\Assignment\Weighted\EffectiveWeights;
use App\Services\Assignment\Weighted\SmoothWeightedRoundRobin;
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
}
