<?php

namespace App\Services\Replenishment;

use App\Constants\OrderStatus;
use App\Models\InventoryPurchase;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Finance\CashDashboard;
use App\Services\Finance\FinancialCpa;
use App\Services\Finance\OrderEconomics;
use App\Services\Inventory\StockStatus;
use App\Services\Meta\MetaAdsData;
use Illuminate\Support\Facades\DB;

/**
 * Arma los datos del plan de inventario (Módulo 5 de Fran, entrega 3) desde la base, para PlanEngine. No calcula el plan:
 * solo junta lo que el §2 pide consumir de los otros módulos.
 * - Órdenes: unidades entregadas por día (fecha de entrega de order_financials, Módulo 4) en el almacén de su agencia;
 *   pedidos en "Sin Stock" por ciudad; pedidos de los últimos días todavía sin madurar; efectividad madura por producto
 *   (ventana madura del Módulo 4, §34).
 * - Inventario: las cifras de StockStatus (§14) y las compras por recibir con su fecha (§16).
 * - Meta (Módulo 2): gasto de los últimos 7 días contra los 7 anteriores, compras y campañas activas, por producto + ciudad.
 * - Finanzas (Módulo 4): CPA, Target y Break-even por producto + ciudad, costo del producto y caja disponible para
 *   inventario (§84).
 * - Configuración (entrega 2): valores, estado y ciudades por producto, proveedores y rutas.
 * Las líneas viejas sin talla de un producto que tiene tallas se reparten entre sus tallas según lo que se vende de cada
 * una (decisión nuestra: las órdenes de antes de la tarea 4 no guardaban la talla).
 */
class PlanData
{
    public function __construct(
        private StockStatus $stock,
        private ReplenishmentConfig $config,
        private MetaAdsData $meta,
        private FinancialCpa $cpa,
        private CashDashboard $cash,
    ) {
    }

    public function build(float $coverage, ?string $today = null): array
    {
        $today ??= today()->toDateString();
        $transit = Warehouse::transitId();
        $lag = max(1, (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4));
        $from30 = date('Y-m-d', strtotime("{$today} -30 days"));
        $yesterday = date('Y-m-d', strtotime("{$today} -1 day"));
        $daysAgo = fn (string $date) => (int) round((strtotime($today) - strtotime(substr($date, 0, 10))) / 86400);

        $cities = DB::table('cities')->pluck('name', 'id');
        $warehouses = [];
        foreach (DB::table('warehouses')->where('is_active', true)->where('id', '<>', $transit)->orderBy('id')->get(['id', 'name', 'city_id', 'user_id']) as $w) {
            $warehouses[(int) $w->id] = ['id' => (int) $w->id, 'name' => $w->name, 'city_id' => $w->city_id !== null ? (int) $w->city_id : null,
                'city' => $w->city_id !== null ? ($cities[$w->city_id] ?? null) : null];
        }
        $agencyWarehouse = DB::table('warehouses')->whereNotNull('user_id')->where('id', '<>', $transit)->orderByDesc('is_active')->orderBy('id')
            ->get(['id', 'user_id'])->unique('user_id')->pluck('id', 'user_id');

        $variants = [];
        $variantsByProduct = [];
        foreach (DB::table('product_variants')->get(['id', 'product_id', 'title', 'is_active']) as $v) {
            $variants[(int) $v->id] = ['product_id' => (int) $v->product_id, 'title' => $v->title];
            if ($v->is_active) {
                $variantsByProduct[(int) $v->product_id][] = (int) $v->id;
            }
        }

        // ---- Stock (§14) y compras por recibir con su fecha (§16) ----
        $stock = [];
        foreach ($this->stock->rows() as $r) {
            if (!isset($warehouses[$r['warehouse_id']])) {
                continue;
            }
            $stock["{$r['warehouse_id']}|{$r['product_id']}|" . (int) $r['variant_id']] = [
                'available' => $r['available'], 'reserved' => $r['reserved'], 'physical' => $r['physical'], 'review' => $r['review'],
                'in_transit' => $r['in_transit'], 'incoming' => [],
            ];
        }
        foreach (InventoryPurchase::where('status', InventoryPurchase::PENDING)->get(['warehouse_id', 'product_id', 'quantity', 'expected_variants', 'expected_at']) as $pp) {
            $split = array_map('intval', (array) ($pp->expected_variants ?? []));
            $rest = (int) $pp->quantity - array_sum($split);
            if ($rest > 0) {
                $split[0] = $rest;
            }
            foreach ($split as $vid => $qty) {
                $k = "{$pp->warehouse_id}|{$pp->product_id}|" . (int) $vid;
                $stock[$k] ??= ['available' => 0, 'reserved' => 0, 'physical' => 0, 'review' => 0, 'in_transit' => 0, 'incoming' => []];
                $stock[$k]['incoming'][] = ['qty' => $qty, 'expected_at' => $pp->expected_at?->format('Y-m-d')];
            }
        }

        // ---- Entregas por día (§22, §27): order_financials.delivered_date, en el almacén de la agencia de la orden ----
        $delivered = [];
        $rows = DB::table('order_financials as f')
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            ->join('order_products as op', 'op.order_id', '=', 'o.id')
            ->where('f.outcome', 'delivered')->where('f.kind', '<>', 'return')
            ->whereBetween('f.delivered_date', [$from30, $yesterday])
            ->groupBy('o.agency_id', 'o.warehouse_id', 'op.product_id', 'op.variant_id', 'f.delivered_date')
            ->selectRaw('o.agency_id, o.warehouse_id, op.product_id, op.variant_id, f.delivered_date AS day, SUM(op.quantity) AS units')
            ->get();
        foreach ($rows as $r) {
            $w = $r->agency_id !== null ? ($agencyWarehouse[$r->agency_id] ?? null) : ($r->warehouse_id !== null ? (int) $r->warehouse_id : null);
            if ($w === null || !isset($warehouses[(int) $w])) {
                continue;
            }
            $k = "{$w}|{$r->product_id}|" . (int) $r->variant_id;
            $ago = $daysAgo($r->day);
            $delivered[$k][$ago] = ($delivered[$k][$ago] ?? 0) + (float) $r->units;
        }
        $delivered = $this->spreadUnsized($delivered, $variantsByProduct);

        // Días desde la última entrega (stock muerto, §74)
        $lastDelivery = [];
        $rows = DB::table('order_financials as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('order_products as op', 'op.order_id', '=', 'o.id')
            ->where('f.outcome', 'delivered')->where('f.kind', '<>', 'return')->whereNotNull('f.delivered_date')
            ->groupBy('o.agency_id', 'o.warehouse_id', 'op.product_id', 'op.variant_id')
            ->selectRaw('o.agency_id, o.warehouse_id, op.product_id, op.variant_id, MAX(f.delivered_date) AS last_day')->get();
        foreach ($rows as $r) {
            $w = $r->agency_id !== null ? ($agencyWarehouse[$r->agency_id] ?? null) : ($r->warehouse_id !== null ? (int) $r->warehouse_id : null);
            if ($w === null) {
                continue;
            }
            $ago = $daysAgo($r->last_day);
            $k = "{$w}|{$r->product_id}|" . (int) $r->variant_id;
            $lastDelivery[$k] = isset($lastDelivery[$k]) ? min($lastDelivery[$k], $ago) : $ago;
            if (!$r->variant_id && !empty($variantsByProduct[(int) $r->product_id])) {
                foreach ($variantsByProduct[(int) $r->product_id] as $vid) {
                    $kv = "{$w}|{$r->product_id}|{$vid}";
                    $lastDelivery[$kv] = isset($lastDelivery[$kv]) ? min($lastDelivery[$kv], $ago) : $ago;
                }
            }
        }

        // ---- Sin Stock (§24): pedidos que hoy están en "Sin Stock", por ciudad y día de creación ----
        $sinStockId = DB::table('statuses')->where('description', OrderStatus::SIN_STOCK)->value('id');
        $sinStock = [];
        if ($sinStockId) {
            $rows = DB::table('orders as o')->join('order_products as op', 'op.order_id', '=', 'o.id')
                ->where('o.status_id', $sinStockId)->whereNotNull('o.city_id')
                ->whereBetween('o.created_at', [$from30 . ' 00:00:00', $yesterday . ' 23:59:59'])
                ->groupBy('o.city_id', 'op.product_id', 'op.variant_id', DB::raw('DATE(o.created_at)'))
                ->selectRaw('o.city_id, op.product_id, op.variant_id, DATE(o.created_at) AS day, SUM(op.quantity) AS units')->get();
            foreach ($rows as $r) {
                $k = "{$r->city_id}|{$r->product_id}|" . (int) $r->variant_id;
                $ago = $daysAgo($r->day);
                $sinStock[$k][$ago] = ($sinStock[$k][$ago] ?? 0) + (float) $r->units;
            }
            $sinStock = $this->spreadUnsized($sinStock, $variantsByProduct);
        }

        // ---- Pedidos de los últimos días (§34, §35), por producto + ciudad ----
        $recent = [];
        $rows = DB::table('orders as o')->join('order_products as op', 'op.order_id', '=', 'o.id')->whereNotNull('o.city_id')
            ->where('o.created_at', '>=', date('Y-m-d', strtotime("{$today} -{$lag} days")) . ' 00:00:00')
            ->where('o.created_at', '<=', $yesterday . ' 23:59:59')
            ->groupBy('op.product_id', 'o.city_id')->selectRaw('op.product_id, o.city_id, SUM(op.quantity) AS units')->get();
        foreach ($rows as $r) {
            $recent["{$r->product_id}|{$r->city_id}"] = (float) $r->units;
        }

        // ---- Efectividad madura (§34): entregadas / creadas en la ventana madura del Módulo 4 ----
        $matureTo = date('Y-m-d', strtotime("{$today} -{$lag} days"));
        $matureFrom = date('Y-m-d', strtotime("{$matureTo} -14 days"));
        $eff = DB::table('order_financials')->where('kind', 'normal')->whereBetween('created_date', [$matureFrom, $matureTo])
            ->whereNotNull('product_id')->groupBy('product_id')
            ->selectRaw("product_id, COUNT(*) AS created, SUM(outcome = 'delivered') AS delivered")->get()->keyBy('product_id');
        $globalCreated = (int) $eff->sum('created');
        $globalEff = $globalCreated > 0 ? (float) $eff->sum('delivered') / $globalCreated : 0.5;

        // ---- Histórico de cada producto (§29): días desde su primer pedido ----
        $firstOrder = DB::table('order_products as op')->join('orders as o', 'o.id', '=', 'op.order_id')->groupBy('op.product_id')
            ->selectRaw('op.product_id, MIN(o.created_at) AS first_at')->pluck('first_at', 'product_id');

        // ---- Configuración, proveedor preferido y costo (§17–§20, §86) ----
        $settings = DB::table('product_inventory_settings')->get()->keyBy('product_id');
        $availability = DB::table('product_city_availability')->get()->groupBy('product_id');
        $suppliers = DB::table('supplier_products as sp')->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->where('sp.is_active', true)->where('s.is_active', true)
            ->orderByDesc('sp.is_preferred')->orderBy('sp.lead_time_days')->orderBy('sp.id')
            ->get(['sp.product_id', 'sp.supplier_id', 's.name', 'sp.lead_time_days', 'sp.receiving_warehouse_id', 'sp.city_id', 'sp.expected_unit_cost_usd'])
            ->groupBy('product_id');
        $lastLotCost = DB::table('inventory_lots')->where('kind', 'purchase')->whereNotNull('unit_cost_usd')->orderByDesc('received_at')->orderByDesc('id')
            ->get(['product_id', 'unit_cost_usd'])->unique('product_id')->pluck('unit_cost_usd', 'product_id');
        $products = [];
        foreach (DB::table('products')->get(['id', 'title', 'showable_name', 'cost_usd']) as $p) {
            $set = $settings->get($p->id);
            $sup = ($suppliers->get($p->id) ?? collect())->first();
            $receiving = $sup?->receiving_warehouse_id ? (int) $sup->receiving_warehouse_id : null;
            if ($sup && !$receiving && $sup->city_id) {
                // §20: sin almacén habitual, llega a un almacén de la ciudad del proveedor
                $receiving = collect($warehouses)->firstWhere('city_id', (int) $sup->city_id)['id'] ?? null;
            }
            $cost = $sup?->expected_unit_cost_usd ?? $lastLotCost[$p->id] ?? $p->cost_usd;
            $first = $firstOrder[$p->id] ?? null;
            $e = $eff->get($p->id);
            $products[(int) $p->id] = [
                'name' => $p->showable_name ?: $p->title,
                'status' => $set->commercial_status ?? 'active',
                'coverage_days' => $set?->coverage_days !== null && $set !== null ? (float) $set->coverage_days : null,
                'safety_days' => $set?->safety_days !== null && $set !== null ? (float) $set->safety_days : null,
                'sin_stock_pct' => $set?->sin_stock_pct !== null && $set !== null ? (int) $set->sin_stock_pct : null,
                'cities' => ($availability->get($p->id) ?? collect())->mapWithKeys(fn ($a) => [(int) $a->city_id => (bool) $a->is_available])->all(),
                'has_variants' => !empty($variantsByProduct[(int) $p->id]),
                'history_days' => $first ? max(0, $daysAgo($first)) : 0,
                'effectiveness' => $e && $e->created >= 10 ? (float) $e->delivered / (int) $e->created : null,
                'unit_cost' => $cost !== null && (float) $cost > 0 ? round((float) $cost, 4) : null,
                'supplier' => $sup ? ['id' => (int) $sup->supplier_id, 'name' => $sup->name, 'lead_time_days' => (float) $sup->lead_time_days,
                    'receiving_warehouse_id' => $receiving] : null,
            ];
        }

        // ---- Meta (Módulo 2) por producto + ciudad (§36–§39) ----
        $meta = [];
        $split = date('Y-m-d', strtotime("{$today} -7 days"));
        foreach ($this->meta->dailySpend(date('Y-m-d', strtotime("{$today} -14 days")), $yesterday, ['product', 'city']) as $r) {
            $k = "{$r['product_id']}|{$r['city_id']}";
            $meta[$k] ??= ['spend_recent' => 0.0, 'spend_prev' => 0.0, 'purchases_recent' => 0.0, 'campaigns' => 0, 'active' => 0];
            if ($r['date'] >= $split) {
                $meta[$k]['spend_recent'] += $r['spend'];
                $meta[$k]['purchases_recent'] += (float) ($r['purchases'] ?? 0);
            } else {
                $meta[$k]['spend_prev'] += $r['spend'];
            }
        }
        $campaigns = DB::table('meta_campaigns')->whereNotNull('product_id')->whereNotNull('city_id')->groupBy('product_id', 'city_id')
            ->selectRaw("product_id, city_id, COUNT(*) AS n, SUM(effective_status = 'ACTIVE') AS active")->get();
        foreach ($campaigns as $c) {
            $k = "{$c->product_id}|{$c->city_id}";
            $meta[$k] ??= ['spend_recent' => 0.0, 'spend_prev' => 0.0, 'purchases_recent' => 0.0, 'campaigns' => 0, 'active' => 0];
            $meta[$k]['campaigns'] = (int) $c->n;
            $meta[$k]['active'] = (int) $c->active;
        }

        // ---- CPA (Módulo 4) por producto + ciudad (§40–§43) ----
        $cpa = [];
        foreach ($this->cpa->latest() as $k => $v) {
            $cpa[$k] = ['target' => $v->target !== null ? (float) $v->target : null, 'break_even' => $v->break_even !== null ? (float) $v->break_even : null,
                'meta_cpa' => $v->meta_cpa !== null ? (float) $v->meta_cpa : null];
        }

        // ---- Caja disponible para inventario (§84) ----
        try {
            $cash = (float) ($this->cash->build($today, $today)['inventory_capital_usd'] ?? 0);
        } catch (\Throwable) {
            $cash = null;
        }

        $routes = [];
        foreach (DB::table('replenishment_routes')->where('is_active', true)->get() as $r) {
            $routes[] = ['id' => (int) $r->id, 'name' => $r->name, 'lead_time_days' => (float) $r->lead_time_days,
                'stops' => DB::table('replenishment_route_stops')->where('route_id', $r->id)->orderBy('position')->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all()];
        }
        $avgTrip = DB::table('inventory_transfer_trips')->whereNull('voided_at')->where('trip_date', '>=', date('Y-m-d', strtotime("{$today} -90 days")))
            ->where('unit_cost_usd', '>', 0)->avg('unit_cost_usd');

        return [
            'today' => $today,
            'coverage_days' => $coverage,
            'settings' => $this->config->settings(),
            'maturity_lag_days' => $lag,
            'warehouses' => $warehouses,
            'routes' => $routes,
            'products' => $products,
            'variants' => $variants,
            'stock' => $stock,
            'delivered' => $delivered,
            'days_since_delivery' => $lastDelivery,
            'sin_stock' => $sinStock,
            'recent_created' => $recent,
            'global_effectiveness' => round($globalEff, 4),
            'meta' => $meta,
            'cpa' => $cpa,
            'cash_available' => $cash,
            'transfer_unit_cost' => $avgTrip !== null ? round((float) $avgTrip, 4) : null,
        ];
    }

    /**
     * Las unidades entregadas entre dos fechas, con la misma atribución que el plan: el almacén de la agencia de la orden y
     * las unidades sin talla repartidas entre las tallas (entrega 4: consumo real del horizonte, §97, §98).
     *
     * @return array<string, float> "almacén|producto|variante" => unidades
     */
    public function deliveredBetween(string $from, string $to): array
    {
        $transit = Warehouse::transitId();
        $agencyWarehouse = DB::table('warehouses')->whereNotNull('user_id')->where('id', '<>', $transit)->orderByDesc('is_active')->orderBy('id')
            ->get(['id', 'user_id'])->unique('user_id')->pluck('id', 'user_id');
        $variantsByProduct = [];
        foreach (DB::table('product_variants')->where('is_active', true)->get(['id', 'product_id']) as $v) {
            $variantsByProduct[(int) $v->product_id][] = (int) $v->id;
        }
        $series = [];
        $rows = DB::table('order_financials as f')->join('orders as o', 'o.id', '=', 'f.order_id')->join('order_products as op', 'op.order_id', '=', 'o.id')
            ->where('f.outcome', 'delivered')->where('f.kind', '<>', 'return')->whereBetween('f.delivered_date', [$from, $to])
            ->groupBy('o.agency_id', 'o.warehouse_id', 'op.product_id', 'op.variant_id')
            ->selectRaw('o.agency_id, o.warehouse_id, op.product_id, op.variant_id, SUM(op.quantity) AS units')->get();
        foreach ($rows as $r) {
            $w = $r->agency_id !== null ? ($agencyWarehouse[$r->agency_id] ?? null) : ($r->warehouse_id !== null ? (int) $r->warehouse_id : null);
            if ($w === null || (int) $w === $transit) {
                continue;
            }
            $k = "{$w}|{$r->product_id}|" . (int) $r->variant_id;
            $series[$k][0] = ($series[$k][0] ?? 0) + (float) $r->units;
        }

        return array_map(fn ($days) => array_sum($days), $this->spreadUnsized($series, $variantsByProduct));
    }

    /**
     * Las unidades sin talla de un producto que tiene tallas se reparten entre sus tallas según lo que se vende de cada una
     * (en ese almacén o ciudad; si no hay, en todo el producto; si tampoco, en partes iguales).
     *
     * @param array<string, array<int, float>> $series  "lugar|producto|variante" => [días atrás => unidades]
     */
    private function spreadUnsized(array $series, array $variantsByProduct): array
    {
        $byProduct = [];
        foreach ($series as $k => $days) {
            [$place, $p, $v] = explode('|', $k);
            if ((int) $v) {
                $byProduct[(int) $p][(int) $v] = ($byProduct[(int) $p][(int) $v] ?? 0) + array_sum($days);
            }
        }
        foreach ($series as $k => $days) {
            [$place, $p, $v] = explode('|', $k);
            $p = (int) $p;
            if ((int) $v || empty($variantsByProduct[$p])) {
                continue;
            }
            $local = [];
            foreach ($variantsByProduct[$p] as $vid) {
                $local[$vid] = array_sum($series["{$place}|{$p}|{$vid}"] ?? []);
            }
            $weights = array_sum($local) > 0 ? $local : (array_sum($byProduct[$p] ?? []) > 0 ? array_intersect_key($byProduct[$p], $local) + array_fill_keys(array_keys($local), 0) : array_fill_keys(array_keys($local), 1));
            $total = array_sum($weights);
            if ($total <= 0) {
                continue;
            }
            foreach ($weights as $vid => $wgt) {
                if ($wgt <= 0) {
                    continue;
                }
                foreach ($days as $ago => $units) {
                    $series["{$place}|{$p}|{$vid}"][$ago] = ($series["{$place}|{$p}|{$vid}"][$ago] ?? 0) + $units * $wgt / $total;
                }
            }
            unset($series[$k]);
        }

        return $series;
    }
}
