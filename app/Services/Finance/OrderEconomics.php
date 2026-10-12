<?php

namespace App\Services\Finance;

use App\Constants\OrderStatus;
use App\Models\AgencyClosureRow;
use App\Models\AgencyWeeklyClosure;
use App\Models\OrderDeliveryRoute;
use App\Models\OrderFinancial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcula order_financials, la economía de cada orden (Módulo 4 de Fran, entrega 1). Trabaja por lotes de órdenes con
 * pocas consultas, para poder rehacer todas cada noche.
 *
 * - Fechas (§3): creación (cohortes, CPA) y entrega (ingreso). orders.delivered_at se repara aquí si algún cambio de
 *   estado no pasó por el observer.
 * - Ingreso (§4, R1): solo si se entregó, el total en USD de la orden; el upsell aparte (§7). Una devolución anula el
 *   ingreso de la original (R3): los reembolsos (orders.refunded_usd) y las órdenes de devolución antiguas entregadas.
 * - Costo del producto (§9, R5): cantidad × products.cost_usd de lo entregado, PROVISIONAL hasta que haya lotes. Un
 *   cambio carga la pieza que sale (la que vuelve queda para revisión, R3); una devolución no, porque el producto se
 *   quedó con el cliente.
 * - Delivery (§5, §18, §19): todas las carreras no anuladas, entregada o no. El costo aprobado en un cierre listo para
 *   pago o pagado; si no, el esperado de su ruta si está calculada; si no, 0 y se cuenta como "sin costo". Antes del
 *   Módulo 3 no había carreras: se toma lo que se le pagaba a la agencia por esa orden (earnings "agencia").
 * - Comisiones (§22, §23, R9): las de la vendedora, el upsell, la líder y el repartidor propio, solo si se entregó.
 */
class OrderEconomics
{
    private const CHUNK = 500;

    private ?array $statusIds = null;

    /** Marca órdenes para recalcular (las crea en la tabla si no estaban). Lo llaman los observers. */
    public static function markStale(int|array $orderIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $orderIds))));
        if ($ids === []) {
            return;
        }
        $now = self::stamp();
        DB::table('order_financials')->upsert(
            array_map(fn ($id) => ['order_id' => $id, 'stale_at' => $now], $ids),
            ['order_id'],
            ['stale_at']
        );
    }

    /** Todas las órdenes de una consulta (ids de órdenes), sin traerlas a memoria: un costo de producto nuevo, la noche. */
    public static function markStaleWhere(\Illuminate\Database\Query\Builder $orderIds): int
    {
        $now = self::stamp();
        $updated = DB::table('order_financials')->whereIn('order_id', (clone $orderIds))->update(['stale_at' => $now]);
        $missing = DB::table('orders')->whereIn('id', (clone $orderIds))
            ->whereNotExists(fn ($q) => $q->from('order_financials as f')->whereColumn('f.order_id', 'orders.id'))
            ->selectRaw('id, ?', [$now]);
        $inserted = DB::table('order_financials')->insertUsing(['order_id', 'stale_at'], $missing);

        return $updated + (int) $inserted;
    }

    public static function markAllStale(): int
    {
        return self::markStaleWhere(DB::table('orders')->select('id'));
    }

    /**
     * Recalcula las órdenes marcadas hasta que no quede ninguna o se acabe el tiempo. Las que se marquen mientras corre
     * quedan para la siguiente vuelta del scheduler.
     * @return int órdenes recalculadas
     */
    public function rebuildStale(int $maxSeconds = 50): int
    {
        $runStartedAt = self::stamp();
        $until = microtime(true) + $maxSeconds;
        $done = 0;
        while (microtime(true) < $until) {
            $ids = DB::table('order_financials')->whereNotNull('stale_at')->where('stale_at', '<', $runStartedAt)
                ->orderBy('order_id')->limit(self::CHUNK)->pluck('order_id')->all();
            if ($ids === []) {
                break;
            }
            try {
                $done += $this->rebuild($ids);
            } catch (\Throwable $e) {
                // Una orden con datos raros no frena a las demás: va una por una, y la que falla queda en el log y se
                // desmarca (la noche la vuelve a intentar)
                foreach ($ids as $id) {
                    try {
                        $done += $this->rebuild([$id]);
                    } catch (\Throwable $e) {
                        report($e);
                        DB::table('order_financials')->where('order_id', $id)->update(['stale_at' => null]);
                    }
                }
            }
        }

        return $done;
    }

    /**
     * La hora con microsegundos, de PHP (la zona de la app) y no de la base: un cambio en el mismo segundo que el
     * recálculo se distingue y no se pierde.
     */
    private static function stamp(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }

    public static function pendingCount(): int
    {
        return DB::table('order_financials')->whereNotNull('stale_at')->count();
    }

    /**
     * Recalcula estas órdenes ya.
     * @param int[] $orderIds
     * @return int órdenes recalculadas
     */
    public function rebuild(array $orderIds): int
    {
        $done = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $orderIds))), self::CHUNK) as $ids) {
            $done += $this->rebuildChunk($ids);
        }

        return $done;
    }

    private function rebuildChunk(array $ids): int
    {
        // Una fila marcada desde este momento sigue marcada y se recalcula en la siguiente vuelta
        $startedAt = self::stamp();
        $st = $this->statusIds();

        $orders = DB::table('orders')->whereIn('id', $ids)->get([
            'id', 'status_id', 'created_at', 'delivered_at', 'updated_at', 'current_total_price', 'refunded_usd', 'city_id',
            'agency_id', 'is_exchange', 'is_return', 'parent_order_id', 'was_shipped',
        ])->keyBy('id');
        if ($orders->isEmpty()) {
            DB::table('order_financials')->whereIn('order_id', $ids)->delete();
            return 0;
        }
        $ids = $orders->keys()->all();

        $lines = DB::table('order_products as op')->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->whereIn('op.order_id', $ids)->orderBy('op.id')
            ->get(['op.order_id', 'op.product_id', 'op.price', 'op.quantity', 'op.is_upsell', 'p.cost_usd'])->groupBy('order_id');

        $trips = DB::table('agency_trips as t')->leftJoin('order_delivery_routes as r', 'r.id', '=', 't.delivery_route_id')
            ->whereIn('t.order_id', $ids)->whereNull('t.voided_at')
            ->get(['t.id', 't.order_id', 't.earning_id', 'r.calculation_status', 'r.expected_cost'])->groupBy('order_id');
        $tripIds = $trips->flatten(1)->pluck('id')->all();
        $tripEarningIds = array_flip($trips->flatten(1)->pluck('earning_id')->filter()->all());
        // §19: el costo aprobado de cada carrera en un cierre listo para pago o pagado
        $approved = $tripIds === [] ? collect() : DB::table('agency_closure_rows as cr')
            ->join('agency_weekly_closures as c', 'c.id', '=', 'cr.closure_id')
            ->where('cr.is_current', true)->whereIn('cr.match_status', [AgencyClosureRow::MATCHED, AgencyClosureRow::MANUAL])
            ->whereIn('c.status', [AgencyWeeklyClosure::READY, AgencyWeeklyClosure::PAID])
            ->whereIn('cr.matched_trip_id', $tripIds)
            ->groupBy('cr.matched_trip_id')->select('cr.matched_trip_id', DB::raw('SUM(COALESCE(cr.approved_cost, 0)) AS cost'))
            ->pluck('cost', 'matched_trip_id');

        $earnings = DB::table('earnings')->whereIn('order_id', $ids)->get(['id', 'order_id', 'role_type', 'amount_usd'])->groupBy('order_id');
        $leader = DB::table('leader_commissions')->whereIn('order_id', $ids)->groupBy('order_id')
            ->select('order_id', DB::raw('SUM(amount_usd) AS total'))->pluck('total', 'order_id');
        // R3: una devolución antigua (orden hija is_return) ya entregada anula el ingreso de la original
        $returned = array_flip(DB::table('orders')->whereIn('parent_order_id', $ids)->where('is_return', true)
            ->where('status_id', $st['delivered'])->pluck('parent_order_id')->all());

        $rows = [];
        foreach ($orders as $o) {
            $o->delivered_at = $this->repairDeliveredAt($o, $st['delivered']);
            $rows[] = $this->economics($o, $lines->get($o->id, collect()), $trips->get($o->id, collect()), $approved,
                $earnings->get($o->id, collect()), $tripEarningIds, (float) ($leader[$o->id] ?? 0), isset($returned[$o->id]), $st);
        }
        DB::table('order_financials')->upsert($rows, ['order_id'], array_values(array_diff(array_keys($rows[0]), ['order_id'])));
        DB::table('order_financials')->whereIn('order_id', $ids)->where('stale_at', '<', $startedAt)->update(['stale_at' => null]);

        return count($rows);
    }

    private function economics(object $o, Collection $lines, Collection $trips, Collection $approved, Collection $earnings,
        array $tripEarningIds, float $leader, bool $hasDeliveredReturn, array $st): array
    {
        $outcome = match ((int) $o->status_id) {
            $st['delivered'] => OrderFinancial::DELIVERED,
            $st['cancelled'], $st['rejected'] => OrderFinancial::NOT_DELIVERED,
            default => OrderFinancial::OPEN,
        };
        $delivered = $outcome === OrderFinancial::DELIVERED;
        $kind = $o->is_exchange ? OrderFinancial::KIND_EXCHANGE : ($o->is_return ? OrderFinancial::KIND_RETURN : OrderFinancial::KIND_NORMAL);

        // El producto principal: la línea que no es upsell de mayor importe (si solo hay upsells, la mayor de todas)
        $main = $lines->where('is_upsell', false)->sortByDesc(fn ($l) => (float) $l->price * (int) $l->quantity)->first()
            ?? $lines->sortByDesc(fn ($l) => (float) $l->price * (int) $l->quantity)->first();

        $total = round((float) $o->current_total_price, 2);
        $upsellLines = $lines->where('is_upsell', true);
        $revenueUpsell = $delivered ? round(min($total, $upsellLines->sum(fn ($l) => (float) $l->price * (int) $l->quantity)), 2) : 0.0;
        $revenueMain = $delivered ? round($total - $revenueUpsell, 2) : 0.0;
        $refunded = $delivered ? round(min($total, $hasDeliveredReturn ? $total : (float) $o->refunded_usd), 2) : 0.0;

        $cogs = ['main' => 0.0, 'upsell' => 0.0];
        $missing = 0;
        if ($delivered && $kind !== OrderFinancial::KIND_RETURN) {
            foreach ($lines as $l) {
                if ($l->cost_usd === null || (float) $l->cost_usd <= 0) {
                    $missing++;
                    continue;
                }
                $cogs[$l->is_upsell ? 'upsell' : 'main'] += (float) $l->cost_usd * (int) $l->quantity;
            }
        }

        $delivery = 0.0;
        $final = 0;
        $noCost = 0;
        foreach ($trips as $t) {
            if (isset($approved[$t->id])) {
                $delivery += (float) $approved[$t->id];
                $final++;
            } elseif ($t->calculation_status === OrderDeliveryRoute::CALCULATED && $t->expected_cost !== null) {
                $delivery += (float) $t->expected_cost;
            } else {
                $noCost++;
            }
        }
        // Antes del Módulo 3: lo que se le pagaba a la agencia (solo si la orden salió, como en la nómina de agencias)
        $legacy = $o->was_shipped ? round($earnings->where('role_type', 'agencia')
            ->reject(fn ($e) => isset($tripEarningIds[$e->id]))->sum(fn ($e) => (float) $e->amount_usd), 2) : 0.0;
        $legacyTrips = $o->was_shipped ? $earnings->where('role_type', 'agencia')->reject(fn ($e) => isset($tripEarningIds[$e->id]))->count() : 0;

        $sum = fn (string $role) => $delivered ? round($earnings->where('role_type', $role)->sum(fn ($e) => (float) $e->amount_usd), 2) : 0.0;

        return [
            'order_id' => $o->id,
            'kind' => $kind,
            'parent_order_id' => $o->parent_order_id,
            'created_date' => Carbon::parse($o->created_at)->toDateString(),
            'delivered_date' => $delivered && $o->delivered_at ? Carbon::parse($o->delivered_at)->toDateString() : null,
            'outcome' => $outcome,
            'status_id' => $o->status_id,
            'product_id' => $main?->product_id,
            'city_id' => $o->city_id,
            'agency_id' => $o->agency_id,
            'units_main' => (int) $lines->where('is_upsell', false)->sum('quantity'),
            'units_upsell' => (int) $upsellLines->sum('quantity'),
            'revenue_main' => $revenueMain,
            'revenue_upsell' => $revenueUpsell,
            'revenue_refunded' => $refunded,
            'cogs_main' => round($cogs['main'], 2),
            'cogs_upsell' => round($cogs['upsell'], 2),
            'cogs_is_provisional' => true, // hasta la entrega 2 (lotes y FIFO)
            'cogs_missing_lines' => $missing,
            'delivery_cost' => round($delivery + $legacy, 2),
            'delivery_trips' => $trips->count() + $legacyTrips,
            'delivery_final_trips' => $final,
            'delivery_missing_trips' => $noCost,
            'delivery_legacy_cost' => $legacy,
            'commission_seller' => $sum('vendedor'),
            'commission_upsell' => $sum('upsell'),
            'commission_leader' => $delivered ? round($leader, 2) : 0.0,
            'commission_deliverer' => $sum('repartidor'),
            'computed_at' => now(),
        ];
    }

    /** §3: la fecha de entrega, aunque el cambio de estado no haya pasado por el observer (actualizaciones masivas). */
    private function repairDeliveredAt(object $o, int $deliveredId): ?string
    {
        $isDelivered = (int) $o->status_id === $deliveredId;
        if ($isDelivered && $o->delivered_at === null) {
            $at = collect([
                DB::table('order_tracking_comprehensive_logs')->where('order_id', $o->id)->where('to_status_id', $deliveredId)->max('created_at'),
                DB::table('order_status_logs')->where('order_id', $o->id)->where('to_status_id', $deliveredId)->max('created_at'),
            ])->filter()->max() ?? $o->updated_at;
            DB::table('orders')->where('id', $o->id)->update(['delivered_at' => $at]);

            return $at;
        }
        if (!$isDelivered && $o->delivered_at !== null) {
            DB::table('orders')->where('id', $o->id)->update(['delivered_at' => null]);

            return null;
        }

        return $o->delivered_at;
    }

    /** @return array{delivered:int, cancelled:int, rejected:int} */
    private function statusIds(): array
    {
        if ($this->statusIds === null) {
            $ids = DB::table('statuses')->whereIn('description', [OrderStatus::ENTREGADO, OrderStatus::CANCELADO, OrderStatus::RECHAZADO])
                ->pluck('id', 'description');
            $this->statusIds = [
                'delivered' => (int) ($ids[OrderStatus::ENTREGADO] ?? -1),
                'cancelled' => (int) ($ids[OrderStatus::CANCELADO] ?? -2),
                'rejected' => (int) ($ids[OrderStatus::RECHAZADO] ?? -3),
            ];
        }

        return $this->statusIds;
    }
}
