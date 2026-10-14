<?php

namespace App\Services\Finance;

use App\Constants\OrderStatus;
use App\Models\AgencyClosureRow;
use App\Models\AgencyWeeklyClosure;
use App\Models\InventoryLot;
use App\Models\OrderDeliveryRoute;
use App\Models\OrderFinancial;
use App\Models\Setting;
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
 * - Costo del producto (§9, §11, R5; entrega 2e): el de los lotes de lo que la orden sacó del inventario (FIFO, lo
 *   asigna LotLedger), y su logística atribuible, la parte de los viajes de traslado de esas unidades (§14). Lo que no
 *   tiene lote (salió antes del corte, o sin stock) va a products.cost_usd y queda PROVISIONAL (cogs_source catalog o
 *   mixed). Un cambio carga la pieza que sale (la que vuelve queda para revisión, R3); una devolución no, porque el
 *   producto se quedó con el cliente.
 * - Delivery (§5, §18, §19): todas las carreras no anuladas, entregada o no. El costo aprobado en un cierre listo para
 *   pago o pagado; si no, el esperado de su ruta si está calculada; si no, 0 y se cuenta como "sin costo". Antes del
 *   Módulo 3 no había carreras: se toma lo que se le pagaba a la agencia por esa orden (earnings "agencia").
 * - Comisiones (§22, §23, R9): las de la vendedora, el upsell, la líder y el repartidor propio, solo si se entregó.
 */
class OrderEconomics
{
    private const CHUNK = 500;

    /** §60: los últimos días todavía no son una cohorte madura (por defecto, 4). */
    public const SETTING_MATURITY_LAG = 'finance.maturity_lag_days';

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
        $requested = $ids;

        $orders = DB::table('orders')->whereIn('id', $ids)->get([
            'id', 'status_id', 'created_at', 'delivered_at', 'updated_at', 'current_total_price', 'refunded_usd', 'city_id',
            'agency_id', 'is_exchange', 'is_return', 'parent_order_id', 'was_shipped',
        ])->keyBy('id');
        if ($orders->isEmpty()) {
            DB::table('order_financials')->whereIn('order_id', $ids)->delete();
            $this->syncCash($requested);
            return 0;
        }
        $ids = $orders->keys()->all();

        $lines = DB::table('order_products as op')->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->whereIn('op.order_id', $ids)->orderBy('op.id')
            ->get(['op.order_id', 'op.product_id', 'op.variant_id', 'op.price', 'op.quantity', 'op.is_upsell', 'p.cost_usd'])->groupBy('order_id');
        $held = $this->heldUnits($ids);

        $trips = DB::table('agency_trips as t')->leftJoin('order_delivery_routes as r', 'r.id', '=', 't.delivery_route_id')
            ->whereIn('t.order_id', $ids)->whereNull('t.voided_at')
            ->get(['t.id', 't.order_id', 't.earning_id', 't.trip_date', 'r.calculation_status', 'r.expected_cost'])->groupBy('order_id');
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

        $earnings = DB::table('earnings')->whereIn('order_id', $ids)->get(['id', 'order_id', 'role_type', 'amount_usd', 'earning_date'])->groupBy('order_id');
        $leader = DB::table('leader_commissions')->whereIn('order_id', $ids)->groupBy('order_id')
            ->select('order_id', DB::raw('SUM(amount_usd) AS total'))->pluck('total', 'order_id');
        // R3: una devolución antigua (orden hija is_return) ya entregada anula el ingreso de la original
        $returned = array_flip(DB::table('orders')->whereIn('parent_order_id', $ids)->where('is_return', true)
            ->where('status_id', $st['delivered'])->pluck('parent_order_id')->all());

        $previous = DB::table('order_financials')->whereIn('order_id', $ids)->whereNotNull('computed_at')
            ->get(['order_id', 'outcome', 'status_id', 'revenue_main', 'revenue_upsell', 'revenue_refunded', 'delivered_date'])->keyBy('order_id');

        $rows = [];
        foreach ($orders as $o) {
            $o->delivered_at = $this->repairDeliveredAt($o, $st['delivered']);
            $rows[] = $this->economics($o, $lines->get($o->id, collect()), $held->get($o->id, collect()), $trips->get($o->id, collect()),
                $approved, $earnings->get($o->id, collect()), $tripEarningIds, (float) ($leader[$o->id] ?? 0), isset($returned[$o->id]), $st);
        }
        DB::table('order_financials')->upsert($rows, ['order_id'], array_values(array_diff(array_keys($rows[0]), ['order_id'])));
        $this->auditMatureChanges($previous, $rows);
        DB::table('order_financials')->whereIn('order_id', $ids)->where('stale_at', '<', $startedAt)->update(['stale_at' => null]);
        $this->syncCash($requested);

        return count($rows);
    }

    /**
     * Entrega 3: la caja de estas órdenes (el efectivo en la agencia, el vuelto de la empresa y la tasa de sus pagos
     * digitales). Un error ahí no frena la economía de las órdenes: queda en el log y la noche lo vuelve a intentar.
     */
    private function syncCash(array $ids): void
    {
        try {
            app(CashLedger::class)->syncOrders($ids);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function economics(object $o, Collection $lines, Collection $held, Collection $trips, Collection $approved,
        Collection $earnings, array $tripEarningIds, float $leader, bool $hasDeliveredReturn, array $st): array
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

        $cogs = $delivered && $kind !== OrderFinancial::KIND_RETURN ? $this->goodsCost($lines, $held)
            : ['main' => 0.0, 'upsell' => 0.0, 'logistics' => 0.0, 'missing' => 0, 'source' => null, 'provisional' => false];

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
        $legacyEarnings = $o->was_shipped ? $earnings->where('role_type', 'agencia')->reject(fn ($e) => isset($tripEarningIds[$e->id])) : collect();
        $legacy = round($legacyEarnings->sum(fn ($e) => (float) $e->amount_usd), 2);
        $legacyTrips = $legacyEarnings->count();
        $deliveredDate = $delivered && $o->delivered_at ? Carbon::parse($o->delivered_at)->toDateString() : null;
        // §3 y §5: la entregada cuenta sus costos el día de la entrega; la que no, el día de su última carrera
        $lastTrip = collect([$trips->max('trip_date'), $legacyEarnings->max('earning_date')])->filter()->max();

        $sum = fn (string $role) => $delivered ? round($earnings->where('role_type', $role)->sum(fn ($e) => (float) $e->amount_usd), 2) : 0.0;

        return [
            'order_id' => $o->id,
            'kind' => $kind,
            'parent_order_id' => $o->parent_order_id,
            'created_date' => Carbon::parse($o->created_at)->toDateString(),
            'delivered_date' => $deliveredDate,
            'cost_date' => $deliveredDate ?? ($lastTrip ? substr((string) $lastTrip, 0, 10) : null),
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
            'logistics_cost' => round($cogs['logistics'], 2),
            'cogs_is_provisional' => $cogs['provisional'],
            'cogs_source' => $cogs['source'],
            'cogs_missing_lines' => $cogs['missing'],
            'delivery_cost' => round($delivery + $legacy, 2),
            'delivery_trips' => $trips->count() + $legacyTrips,
            'delivery_final_trips' => $final + $legacyTrips, // lo pagado antes del Módulo 3 también es definitivo
            'delivery_missing_trips' => $noCost,
            'delivery_legacy_cost' => $legacy,
            'commission_seller' => $sum('vendedor'),
            'commission_upsell' => $sum('upsell'),
            'commission_leader' => $delivered ? round($leader, 2) : 0.0,
            'commission_deliverer' => $sum('repartidor'),
            'computed_at' => now(),
        ];
    }

    /**
     * Lo que cada orden tiene fuera del inventario por lotes, por capa: unidades, costo del lote y logística de la capa.
     * Las unidades que salieron sin lote vienen con layer_id vacío.
     *
     * @param int[] $ids
     * @return Collection<int, Collection<int, object>> por order_id
     */
    private function heldUnits(array $ids): Collection
    {
        return DB::table('inventory_lot_allocations as a')
            ->leftJoin('inventory_lot_layers as l', 'l.id', '=', 'a.layer_id')
            ->leftJoin('inventory_lots as lot', 'lot.id', '=', 'l.lot_id')
            ->where('a.reference_type', 'Order')->whereIn('a.reference_id', $ids)
            ->groupBy('a.reference_id', 'a.product_id', 'a.variant_id', 'a.layer_id')
            ->havingRaw('SUM(a.quantity) < 0')
            ->selectRaw('a.reference_id AS order_id, a.product_id, a.variant_id, a.layer_id, -SUM(a.quantity) AS units,
                MAX(lot.unit_cost_usd) AS unit_cost, MAX(l.landed_cost_per_unit) AS landed, MAX(lot.kind) AS lot_kind')
            ->get()->groupBy('order_id');
    }

    /**
     * §9, §11, §14: el costo de lo entregado y su logística. Por producto y variante, las unidades de la orden se reparten
     * entre sus líneas (primero las de su misma variante): cada una lleva el costo promedio de sus lotes y su logística.
     * Lo que no alcanza a tener lote con costo va a products.cost_usd (provisional); si tampoco hay, la línea queda "sin
     * costo". Los lotes "por completar" (pending_cost) tienen el costo de catálogo: también son provisionales.
     *
     * @return array{main: float, upsell: float, logistics: float, missing: int, source: ?string, provisional: bool}
     */
    private function goodsCost(Collection $lines, Collection $held): array
    {
        // Lo que tiene la orden, por producto y variante
        $pool = [];
        foreach ($held as $h) {
            $p = &$pool[(int) $h->product_id][(int) ($h->variant_id ?? 0)];
            $p ??= ['units' => 0.0, 'costed' => 0.0, 'cost' => 0.0, 'logistics' => 0.0, 'pending' => 0.0];
            $units = (float) $h->units;
            $p['units'] += $units;
            if ($h->layer_id !== null && $h->unit_cost !== null) {
                $p['costed'] += $units;
                $p['cost'] += $units * (float) $h->unit_cost;
                if ($h->lot_kind === InventoryLot::KIND_PENDING_COST) {
                    $p['pending'] += $units;
                }
            }
            $p['logistics'] += $units * (float) ($h->landed ?? 0);
            unset($p);
        }

        $out = ['main' => 0.0, 'upsell' => 0.0, 'logistics' => 0.0, 'missing' => 0, 'source' => null, 'provisional' => false];
        $fromLots = 0.0;
        $fromCatalog = 0.0;
        foreach ($lines as $l) {
            $need = (float) (int) $l->quantity;
            if ($need <= 0) {
                continue;
            }
            $productId = (int) $l->product_id;
            $variant = (int) ($l->variant_id ?? 0);
            $cost = 0.0;
            $costed = 0.0;
            // Primero de su variante; después, de lo demás de ese producto
            foreach (array_unique(array_merge([$variant], array_keys($pool[$productId] ?? []))) as $v) {
                if ($need <= 0 || ($pool[$productId][$v]['units'] ?? 0) <= 0) {
                    continue;
                }
                $p = &$pool[$productId][$v];
                $take = min($need, $p['units']);
                $f = $take / $p['units'];
                $cost += $p['cost'] * $f;
                $costed += $p['costed'] * $f;
                $out['logistics'] += $p['logistics'] * $f;
                if ($p['pending'] * $f > 0) {
                    $out['provisional'] = true;
                }
                foreach (['costed', 'cost', 'logistics', 'pending'] as $k) {
                    $p[$k] -= $p[$k] * $f;
                }
                $p['units'] -= $take;
                $need -= $take;
                unset($p);
            }
            // Lo que no tiene lote con costo: el costo de catálogo, provisional
            $withoutLotCost = (float) (int) $l->quantity - $costed;
            if ($withoutLotCost > 0.0001) {
                if ($l->cost_usd !== null && (float) $l->cost_usd > 0) {
                    $cost += $withoutLotCost * (float) $l->cost_usd;
                    $fromCatalog += $withoutLotCost;
                } else {
                    $out['missing']++;
                }
                $out['provisional'] = true;
            }
            $fromLots += $costed;
            $out[$l->is_upsell ? 'upsell' : 'main'] += $cost;
        }
        $out['source'] = match (true) {
            $fromLots > 0 && $fromCatalog <= 0 && $out['missing'] === 0 => OrderFinancial::COGS_LOTS,
            $fromLots > 0 => OrderFinancial::COGS_MIXED,
            default => OrderFinancial::COGS_CATALOG,
        };

        return $out;
    }

    /**
     * §21: una orden de una cohorte ya madura (creada hace más de finance.maturity_lag_days, §60) que cambia de resultado
     * o de ingreso deja su registro: valor anterior, nuevo, motivo y fecha. Así se explica por qué cambió un día ya cerrado.
     */
    private function auditMatureChanges(Collection $previous, array $rows): void
    {
        if ($previous->isEmpty()) {
            return;
        }
        $matureBefore = now()->subDays((int) Setting::get(self::SETTING_MATURITY_LAG, 4))->toDateString();
        $statuses = null;
        $now = now();
        $entries = [];
        foreach ($rows as $row) {
            $old = $previous->get($row['order_id']);
            if (!$old || $row['created_date'] >= $matureBefore) {
                continue;
            }
            $oldRevenue = round((float) $old->revenue_main + (float) $old->revenue_upsell - (float) $old->revenue_refunded, 2);
            $newRevenue = round($row['revenue_main'] + $row['revenue_upsell'] - $row['revenue_refunded'], 2);
            if ($old->outcome === $row['outcome'] && abs($oldRevenue - $newRevenue) < 0.005) {
                continue;
            }
            $statuses ??= DB::table('statuses')->pluck('description', 'id');
            $status = $statuses[$row['status_id']] ?? 'otro estado';
            $reason = match (true) {
                $row['outcome'] === OrderFinancial::DELIVERED && $old->outcome !== OrderFinancial::DELIVERED
                    => 'Entregada el ' . Carbon::parse($row['delivered_date'])->format('d/m/Y'),
                $old->outcome === OrderFinancial::DELIVERED && $row['outcome'] !== OrderFinancial::DELIVERED
                    => "Dejó de estar entregada: ahora está en \"{$status}\"",
                $old->outcome !== $row['outcome'] => "Pasó a \"{$status}\"",
                (float) $old->revenue_refunded !== $row['revenue_refunded'] => 'Devolución o reembolso',
                default => 'Cambió el total de la orden',
            };
            $entries[] = [
                'action' => 'cohort_changed',
                'subject_type' => 'order',
                'subject_id' => $row['order_id'],
                'before' => json_encode(['outcome' => $old->outcome, 'net_revenue' => $oldRevenue, 'delivered_date' => $old->delivered_date]),
                'after' => json_encode(['outcome' => $row['outcome'], 'net_revenue' => $newRevenue, 'delivered_date' => $row['delivered_date'],
                    'cohort' => $row['created_date']]),
                'reason' => $reason,
                'user_id' => null,
                'created_at' => $now,
            ];
        }
        if ($entries !== []) {
            DB::table('financial_audit_logs')->insert($entries);
        }
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
