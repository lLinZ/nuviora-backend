<?php

namespace App\Services\SalesGroups;

use App\Constants\OrderStatus;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Métricas del grupo de la Líder (Fran, 2026-09-26: "todo lo referente a su grupo de vendedoras").
 *
 * Las órdenes del período son las creadas en esas fechas, y cada una cuenta para la PRIMERA vendedora
 * a la que se le asignó: la misma regla que la pantalla Métricas del Admin (MetricsController, 2A),
 * para que los números coincidan. Sobre esas órdenes:
 * - efectividad = entregadas por ella misma ÷ asignadas (spec §8.1);
 * - cancelaciones = las que hoy están en Cancelado ÷ asignadas;
 * - a agencia = las que llegaron alguna vez a "Asignar a agencia" ÷ asignadas;
 * - upsells = entregadas con al menos un upsell ÷ entregadas (spec §11).
 * Los totales del grupo se calculan con las sumas, no promediando porcentajes (spec §8.2).
 * Las comisiones salen de earnings por fecha de ganancia, igual que "Mis ganancias".
 */
final class GroupMetrics
{
    /**
     * @param  int[]  $userIds
     * @return array{rows: array<int, array>, totals: array}
     */
    public function period(array $userIds, string $start, string $end): array
    {
        $id = fn (string $description) => (int) (Status::where('description', $description)->value('id') ?? 0);
        [$delivered, $cancelled, $agency] = [$id(OrderStatus::ENTREGADO), $id(OrderStatus::CANCELADO), $id(OrderStatus::ASIGNAR_A_AGENCIA)];

        $sellerIds = User::whereHas('role', fn ($q) => $q->where('description', 'Vendedor'))->pluck('id');
        $firstAssignment = DB::table('order_tracking_comprehensive_logs as tl')
            ->join('orders as o', 'o.id', '=', 'tl.order_id')
            ->whereBetween('o.created_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->whereIn('tl.seller_id', $sellerIds)
            ->groupBy('tl.order_id')
            ->selectRaw('MIN(tl.id) as first_log_id');

        $orders = DB::table('order_tracking_comprehensive_logs as tl')
            ->joinSub($firstAssignment, 'fa', 'fa.first_log_id', '=', 'tl.id')
            ->join('orders as o', 'o.id', '=', 'tl.order_id')
            ->whereIn('tl.seller_id', $userIds)
            ->groupBy('tl.seller_id')
            ->selectRaw('
                tl.seller_id as user_id,
                COUNT(*) as assigned,
                SUM(CASE WHEN o.status_id = ? AND o.agent_id = tl.seller_id THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN o.status_id = ? THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN EXISTS (SELECT 1 FROM order_tracking_comprehensive_logs ag WHERE ag.order_id = o.id AND ag.to_status_id = ?) THEN 1 ELSE 0 END) as to_agency,
                SUM(CASE WHEN o.status_id = ? AND o.agent_id = tl.seller_id
                    AND EXISTS (SELECT 1 FROM order_products op WHERE op.order_id = o.id AND op.is_upsell = 1) THEN 1 ELSE 0 END) as delivered_with_upsell
            ', [$delivered, $cancelled, $agency, $delivered])
            ->get()
            ->keyBy('user_id');

        $earnings = DB::table('earnings')
            ->whereIn('user_id', $userIds)
            ->whereIn('role_type', ['vendedor', 'upsell'])
            ->whereBetween('earning_date', [$start, $end])
            ->groupBy('user_id', 'role_type')
            ->selectRaw('user_id, role_type, SUM(amount_usd) as usd')
            ->get()
            ->groupBy('user_id');

        $rows = [];
        foreach ($userIds as $userId) {
            $o = $orders->get($userId);
            $e = $earnings->get($userId, collect())->pluck('usd', 'role_type');
            $rows[$userId] = $this->row(
                (int) ($o->assigned ?? 0),
                (int) ($o->delivered ?? 0),
                (int) ($o->cancelled ?? 0),
                (int) ($o->to_agency ?? 0),
                (int) ($o->delivered_with_upsell ?? 0),
                (float) ($e['vendedor'] ?? 0),
                (float) ($e['upsell'] ?? 0),
            );
        }

        $sum = fn (string $key) => array_sum(array_column($rows, $key));
        $totals = $this->row(
            $sum('assigned'), $sum('delivered'), $sum('cancelled'), $sum('to_agency'),
            $sum('delivered_with_upsell'), $sum('commission_sales'), $sum('commission_upsells'),
        );

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Métricas de cada agencia limitadas a los pedidos del grupo (spec §10): pedidos creados en el
     * período, de una vendedora del grupo, que tienen agencia. Nunca incluye pedidos de otros grupos.
     *
     * @param  int[]  $userIds
     * @return array<int, array>
     */
    public function agencies(array $userIds, string $start, string $end): array
    {
        $id = fn (string $description) => (int) (Status::where('description', $description)->value('id') ?? 0);
        $delivered = $id(OrderStatus::ENTREGADO);
        $novelty = $id(OrderStatus::NOVEDADES);
        $resolved = $id(OrderStatus::NOVEDAD_SOLUCIONADA);
        $pending = Status::whereIn('description', self::AGENCY_PENDING)->pluck('id')->all() ?: [0];
        $in = implode(',', array_map('intval', $pending));

        $base = DB::table('orders as o')
            ->whereIn('o.agent_id', $userIds)
            ->whereNotNull('o.agency_id')
            ->whereBetween('o.created_at', [$start . ' 00:00:00', $end . ' 23:59:59']);

        $rows = (clone $base)
            ->join('users as a', 'a.id', '=', 'o.agency_id')
            ->groupBy('o.agency_id', 'a.names', 'a.surnames')
            ->selectRaw("
                o.agency_id, a.names, a.surnames,
                COUNT(*) as received,
                SUM(CASE WHEN o.status_id = ? THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN o.status_id IN ($in) THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN o.status_id = ? OR EXISTS (SELECT 1 FROM order_tracking_comprehensive_logs n WHERE n.order_id = o.id AND n.to_status_id = ?) THEN 1 ELSE 0 END) as novelties,
                SUM(CASE WHEN o.status_id = ? OR EXISTS (SELECT 1 FROM order_tracking_comprehensive_logs r WHERE r.order_id = o.id AND r.to_status_id = ?) THEN 1 ELSE 0 END) as resolved
            ", [$delivered, $novelty, $novelty, $resolved, $resolved])
            ->orderByDesc('received')
            ->get();

        $byStatus = (clone $base)
            ->join('statuses as s', 's.id', '=', 'o.status_id')
            ->groupBy('o.agency_id', 's.description')
            ->selectRaw('o.agency_id, s.description, COUNT(*) as c')
            ->get()
            ->groupBy('agency_id');

        return $rows->map(fn ($r) => [
            'agency_id' => (int) $r->agency_id,
            'name' => trim($r->names . ' ' . $r->surnames),
            'received' => (int) $r->received,
            'delivered' => (int) $r->delivered,
            'effectiveness' => self::pct((int) $r->delivered, (int) $r->received),
            'pending' => (int) $r->pending,
            'novelties' => (int) $r->novelties,
            'novelties_resolved' => (int) $r->resolved,
            'resolved_pct' => self::pct((int) $r->resolved, (int) $r->novelties),
            'by_status' => $byStatus->get($r->agency_id, collect())
                ->map(fn ($s) => ['status' => $s->description, 'count' => (int) $s->c])
                ->sortByDesc('count')->values()->all(),
        ])->values()->all();
    }

    /** Estados en los que el pedido está en manos de la agencia y todavía no terminó. */
    private const AGENCY_PENDING = [
        OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNAR_REPARTIDOR, OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::EN_RUTA,
    ];

    private function row(int $assigned, int $delivered, int $cancelled, int $toAgency, int $withUpsell, float $sales, float $upsells): array
    {
        return [
            'assigned' => $assigned,
            'delivered' => $delivered,
            'effectiveness' => self::pct($delivered, $assigned),
            'cancelled' => $cancelled,
            'cancelled_pct' => self::pct($cancelled, $assigned),
            'to_agency' => $toAgency,
            'to_agency_pct' => self::pct($toAgency, $assigned),
            'delivered_with_upsell' => $withUpsell,
            'upsell_pct' => self::pct($withUpsell, $delivered),
            'commission_sales' => round($sales, 2),
            'commission_upsells' => round($upsells, 2),
            'commission_total' => round($sales + $upsells, 2),
        ];
    }

    /** null cuando no hay base (0 asignadas): la pantalla muestra "—" en vez de un 0 % engañoso. */
    private static function pct(int $part, int $base): ?float
    {
        return $base > 0 ? round($part / $base * 100, 1) : null;
    }
}
