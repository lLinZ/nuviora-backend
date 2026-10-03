<?php

namespace App\Services\Assignment;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\OrderAssignmentLog;
use App\Models\Shop;
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
 * Cada orden va solo a vendedoras de su tienda (Fran, 2026-10-03: a Gigi le llegaron órdenes de Solo Brillo,
 * donde no está). Si ninguna de las elegidas está en esa tienda, la orden se queda con la de origen.
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
     * @return array{moved: array<int, int>, kept: array<string, int>}  user_id => órdenes que recibió, y por
     *         tienda las que se quedaron con la de origen porque ninguna de las elegidas está en esa tienda.
     */
    public function reassign(int $fromId, array $toIds, array $statusIds, ?int $byUserId): array
    {
        $toIds = array_values(array_diff(array_unique(array_map('intval', $toIds)), [$fromId]));
        if ($toIds === [] || $statusIds === []) {
            return ['moved' => [], 'kept' => []];
        }

        // Mismos % del reparto automático (sin mirar el máximo: es una decisión manual).
        // Si las elegidas no tienen peso (por ejemplo, todas en 0 %), se reparte parejo.
        $all = array_intersect_key(EffectiveWeights::compute($this->assigner->groupInfo($toIds)), array_flip($toIds));
        if (array_sum($all) <= 0) {
            $all = array_fill_keys($toIds, 1.0);
        }
        $shopsOf = $this->shopsOf($toIds);

        $orders = Order::where('agent_id', $fromId)->whereIn('status_id', $statusIds)->orderBy('id')->get(['id', 'shop_id']);

        $result = array_fill_keys($toIds, 0);
        $kept = [];
        $current = []; // un turno por tienda: cada tienda reparte con los % de las suyas
        foreach ($orders as $row) {
            $orderId = $row->id;
            $shopId = $row->shop_id ? (int) $row->shop_id : null;
            $weights = $shopId === null ? $all : array_filter(
                $all,
                fn ($w, $id) => $w > 0 && in_array($shopId, $shopsOf[$id] ?? [], true),
                ARRAY_FILTER_USE_BOTH
            );
            if ($weights === []) {
                $kept[$shopId] = ($kept[$shopId] ?? 0) + 1;
                continue;
            }
            [$to, $current[$shopId ?? 0]] = SmoothWeightedRoundRobin::pick($weights, $current[$shopId ?? 0] ?? []);

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

        $names = $kept ? Shop::whereIn('id', array_keys($kept))->pluck('name', 'id') : collect();
        $keptByShop = [];
        foreach ($kept as $shopId => $n) {
            $keptByShop[$names[$shopId] ?? "tienda #{$shopId}"] = $n;
        }

        return ['moved' => $result, 'kept' => $keptByShop];
    }

    /** ¿Puede esa vendedora recibir órdenes de la tienda de la orden? Sin tienda, sí. */
    public function worksIn(int $userId, ?int $shopId): bool
    {
        return $shopId === null || in_array($shopId, $this->shopsOf([$userId])[$userId] ?? [], true);
    }

    /**
     * Mensaje para cuando quedaron órdenes con la de origen.
     *
     * @param  array<string, int>  $kept
     */
    public static function keptMessage(array $kept): ?string
    {
        if ($kept === []) {
            return null;
        }
        $parts = array_map(fn ($shop, $n) => "{$n} de {$shop}", array_keys($kept), $kept);

        return 'Se quedaron con la vendedora de origen ' . implode(' y ', $parts)
            . ': ninguna de las elegidas está en esa tienda.';
    }

    /** @return array<int, int[]> user_id => tiendas en las que está */
    private function shopsOf(array $userIds): array
    {
        return DB::table('shop_user')->whereIn('user_id', $userIds)->get(['user_id', 'shop_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('shop_id')->map(fn ($id) => (int) $id)->all())
            ->all();
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
