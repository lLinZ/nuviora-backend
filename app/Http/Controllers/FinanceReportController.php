<?php

namespace App\Http\Controllers;

use App\Models\AgencyClosureRow;
use App\Models\AgencyWeeklyClosure;
use App\Models\Order;
use App\Models\OrderDeliveryRoute;
use App\Models\OrderFinancial;
use App\Services\Finance\ProfitAndLoss;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Finanzas › Resumen y Rentabilidad (Módulo 4 de Fran, §47 a §52, §57, §58). Solo el Admin (§81, por la ruta).
 * "Cada cifra debe poder explicarse" (§49): de la empresa al producto y la ciudad, de ahí a los pedidos y de un pedido a
 * sus líneas, carreras y comisiones.
 */
class FinanceReportController extends Controller
{
    public function __construct(private ProfitAndLoss $pnl)
    {
    }

    /** §48 y §58: las tarjetas del período y las del período anterior del mismo largo. */
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        [$prevFrom, $prevTo] = ProfitAndLoss::previousPeriod($from, $to);

        return response()->json([
            'current' => $this->pnl->report($from, $to),
            'previous' => $this->pnl->report($prevFrom, $prevTo),
        ]);
    }

    /** §47, §50 a §52: los niveles de la empresa y por producto, ciudad o producto + ciudad. */
    public function profitability(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $by = $request->validate(['by' => ['nullable', Rule::in(ProfitAndLoss::GROUPS)]])['by'] ?? 'product_city';

        return response()->json($this->pnl->report($from, $to, $by));
    }

    /** §49: los pedidos de un producto, una ciudad o los dos, con lo que aporta cada uno. */
    public function orders(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $data = $request->validate(['product_id' => 'nullable|string', 'city_id' => 'nullable|string', 'page' => 'nullable|integer|min:1']);
        $query = OrderFinancial::query()->from('order_financials as f')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->leftJoin('statuses as s', 's.id', '=', 'o.status_id')
            ->whereBetween('f.cost_date', [$from->toDateString(), $to->toDateString()]);
        foreach (['product_id', 'city_id'] as $key) {
            if (($value = $data[$key] ?? null) !== null) {
                $value === 'none' ? $query->whereNull("f.{$key}") : $query->where("f.{$key}", (int) $value);
            }
        }
        $page = $query->orderByDesc('f.cost_date')->orderByDesc('f.order_id')
            ->select('f.*', 'o.name', 's.description as status')->paginate(50, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'orders' => collect($page->items())->map(fn (OrderFinancial $f) => [
                'order_id' => $f->order_id,
                'name' => $f->name,
                'status' => $f->status,
                'kind' => $f->kind,
                'outcome' => $f->outcome,
                'created_date' => $f->created_date?->toDateString(),
                'delivered_date' => $f->delivered_date?->toDateString(),
                'cost_date' => $f->cost_date?->toDateString(),
                'revenue' => $f->netRevenue(),
                'cogs' => round($f->cogs_main + $f->cogs_upsell, 2),
                'logistics' => round((float) $f->logistics_cost, 2),
                'cogs_source' => $f->cogs_source,
                'delivery' => round($f->delivery_cost + $f->commission_deliverer, 2),
                'commissions' => round($f->commission_seller + $f->commission_upsell + $f->commission_leader, 2),
                'result' => round($f->netRevenue() - $f->totalCost(), 2),
                'cogs_missing_lines' => $f->cogs_missing_lines,
                'delivery_missing_trips' => $f->delivery_missing_trips,
                'delivery_estimated' => $f->delivery_trips > $f->delivery_final_trips + $f->delivery_missing_trips,
            ]),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /** §49: un pedido, línea por línea y carrera por carrera, de dónde sale cada número. */
    public function order(Order $order): JsonResponse
    {
        $f = OrderFinancial::where('order_id', $order->id)->first();
        $lines = DB::table('order_products as op')->leftJoin('products as p', 'p.id', '=', 'op.product_id')->where('op.order_id', $order->id)
            ->orderBy('op.id')->get(['op.id', 'op.showable_name', 'op.title', 'op.size', 'op.quantity', 'op.price', 'op.is_upsell', 'p.title as product', 'p.cost_usd'])
            ->map(fn ($l) => [
                'name' => $l->showable_name ?: ($l->title ?: $l->product),
                'size' => $l->size,
                'quantity' => (int) $l->quantity,
                'price' => (float) $l->price,
                'subtotal' => round((float) $l->price * (int) $l->quantity, 2),
                'is_upsell' => (bool) $l->is_upsell,
                'unit_cost' => $l->cost_usd !== null && (float) $l->cost_usd > 0 ? (float) $l->cost_usd : null,
                'cogs' => $l->cost_usd !== null && (float) $l->cost_usd > 0 ? round((float) $l->cost_usd * (int) $l->quantity, 2) : null,
            ]);

        $trips = DB::table('agency_trips as t')->leftJoin('order_delivery_routes as r', 'r.id', '=', 't.delivery_route_id')
            ->leftJoin('users as a', 'a.id', '=', 't.agency_id')->where('t.order_id', $order->id)->orderBy('t.id')
            ->get(['t.id', 't.type', 't.result', 't.trip_date', 't.voided_at', 't.void_reason', 't.earning_id', 'a.names as agency', 'r.calculation_status',
                'r.expected_cost', 'r.distance_km']);
        $closures = DB::table('agency_closure_rows as cr')->join('agency_weekly_closures as c', 'c.id', '=', 'cr.closure_id')
            ->where('cr.is_current', true)->whereIn('cr.match_status', [AgencyClosureRow::MATCHED, AgencyClosureRow::MANUAL])
            ->whereIn('cr.matched_trip_id', $trips->pluck('id'))->get(['cr.matched_trip_id', 'cr.approved_cost', 'c.status', 'c.period_start', 'c.period_end'])
            ->groupBy('matched_trip_id');
        $tripEarnings = $trips->pluck('earning_id')->filter()->all();
        $earnings = DB::table('earnings as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')->where('e.order_id', $order->id)
            ->get(['e.id', 'e.role_type', 'e.amount_usd', 'e.earning_date', 'u.names']);
        $legacyAgency = $order->was_shipped ? $earnings->where('role_type', 'agencia')->reject(fn ($e) => in_array($e->id, $tripEarnings, true)) : collect();

        return response()->json([
            'order' => ['id' => $order->id, 'name' => $order->name, 'status' => $order->status?->description, 'total' => (float) $order->current_total_price,
                'refunded' => (float) $order->refunded_usd, 'created_at' => $order->created_at?->format('Y-m-d H:i'),
                'delivered_at' => $order->delivered_at ? Carbon::parse($order->delivered_at)->format('Y-m-d H:i') : null,
                'is_exchange' => (bool) $order->is_exchange, 'is_return' => (bool) $order->is_return],
            'financials' => $f ? [
                'outcome' => $f->outcome, 'kind' => $f->kind, 'revenue_main' => $f->revenue_main, 'revenue_upsell' => $f->revenue_upsell,
                'revenue_refunded' => $f->revenue_refunded, 'revenue' => $f->netRevenue(), 'cogs_main' => $f->cogs_main, 'cogs_upsell' => $f->cogs_upsell,
                'logistics' => (float) $f->logistics_cost, 'cogs_source' => $f->cogs_source, 'cogs_is_provisional' => (bool) $f->cogs_is_provisional,
                'delivery' => round($f->delivery_cost + $f->commission_deliverer, 2), 'commission_seller' => $f->commission_seller,
                'commission_upsell' => $f->commission_upsell, 'commission_leader' => $f->commission_leader, 'total_cost' => $f->totalCost(),
                'result' => round($f->netRevenue() - $f->totalCost(), 2), 'computed_at' => $f->computed_at?->format('Y-m-d H:i'),
            ] : null,
            'lines' => $lines,
            // §49 hasta el lote (entrega 2e): de qué lote salió cada unidad, a qué costo y con qué logística
            'lots' => DB::table('inventory_lot_allocations as a')
                ->leftJoin('inventory_lot_layers as l', 'l.id', '=', 'a.layer_id')
                ->leftJoin('inventory_lots as lot', 'lot.id', '=', 'l.lot_id')
                ->leftJoin('inventory_purchases as pu', 'pu.id', '=', 'lot.purchase_id')
                ->leftJoin('suppliers as su', 'su.id', '=', 'pu.supplier_id')
                ->leftJoin('products as p', 'p.id', '=', 'a.product_id')
                ->leftJoin('product_variants as v', 'v.id', '=', 'a.variant_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
                ->where('a.reference_type', 'Order')->where('a.reference_id', $order->id)
                ->groupBy('a.layer_id', 'lot.id', 'lot.kind', 'lot.received_at', 'lot.unit_cost_usd', 'l.landed_cost_per_unit', 'su.name', 'p.title', 'v.title', 'w.name')
                ->havingRaw('SUM(a.quantity) < 0')
                ->selectRaw('lot.id AS lot_id, lot.kind, lot.received_at, lot.unit_cost_usd, l.landed_cost_per_unit AS landed, su.name AS supplier,
                    p.title AS product, v.title AS variant, w.name AS warehouse, -SUM(a.quantity) AS units')
                ->get()->map(fn ($r) => [
                    'lot_id' => $r->lot_id, 'kind' => $r->kind, 'received_at' => $r->received_at ? substr((string) $r->received_at, 0, 10) : null,
                    'supplier' => $r->supplier, 'product' => $r->product, 'variant' => $r->variant, 'warehouse' => $r->warehouse, 'units' => (int) $r->units,
                    'unit_cost' => $r->unit_cost_usd !== null ? (float) $r->unit_cost_usd : null, 'landed' => (float) ($r->landed ?? 0),
                ]),
            'trips' => $trips->map(function ($t) use ($closures) {
                $closure = $closures->get($t->id)?->first(fn ($c) => in_array($c->status, [AgencyWeeklyClosure::READY, AgencyWeeklyClosure::PAID], true));
                [$cost, $source] = match (true) {
                    $t->voided_at !== null => [0.0, 'Anulada: no cuenta'],
                    $closure !== null => [(float) $closures->get($t->id)->whereIn('status', [AgencyWeeklyClosure::READY, AgencyWeeklyClosure::PAID])->sum('approved_cost'),
                        'Definitivo: aprobado en el cierre del ' . Carbon::parse($closure->period_start)->format('d/m') . ' al ' . Carbon::parse($closure->period_end)->format('d/m')],
                    $t->calculation_status === OrderDeliveryRoute::CALCULATED && $t->expected_cost !== null => [(float) $t->expected_cost, 'Provisional: Google Routes, ' . $t->distance_km . ' km'],
                    default => [0.0, 'Sin costo: ' . (OrderDeliveryRoute::STATUS_LABEL[$t->calculation_status] ?? 'sin ruta')],
                };

                return ['date' => $t->trip_date, 'agency' => $t->agency, 'type' => $t->type, 'result' => $t->result, 'cost' => round($cost, 2), 'source' => $source,
                    'void_reason' => $t->void_reason];
            }),
            'legacy_agency' => $legacyAgency->map(fn ($e) => ['date' => $e->earning_date, 'agency' => $e->names, 'cost' => (float) $e->amount_usd])->values(),
            'commissions' => $earnings->whereIn('role_type', ['vendedor', 'upsell', 'repartidor'])
                ->map(fn ($e) => ['role' => $e->role_type, 'user' => $e->names, 'amount' => (float) $e->amount_usd, 'date' => $e->earning_date])->values(),
            'leader' => DB::table('leader_commissions as l')->leftJoin('users as u', 'u.id', '=', 'l.leader_id')->where('l.order_id', $order->id)
                ->get(['u.names as user', 'l.amount_usd as amount', 'l.pct'])->map(fn ($l) => ['user' => $l->user, 'amount' => (float) $l->amount, 'pct' => (float) $l->pct]),
        ]);
    }

    /** El período: por defecto, el mes en curso (§57). */
    private function range(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->startOfDay();
        abort_if($from->diffInDays($to) > 400, 422, 'El período no puede pasar de 400 días.');

        return [$from, $to];
    }
}
