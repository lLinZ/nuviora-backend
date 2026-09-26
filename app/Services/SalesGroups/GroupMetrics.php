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
