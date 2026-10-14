<?php

namespace App\Services\Finance;

use App\Models\City;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Meta\MetaAdsData;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rentabilidad cronológica por niveles (Módulo 4 de Fran, §47):
 *   Ingresos − COGS − logística atribuible − delivery − comisiones vendedoras − comisión líderes − publicidad
 *   − otros costes variables atribuibles = MARGEN DE CONTRIBUCIÓN; − gastos fijos/generales = BENEFICIO OPERATIVO.
 * - Ingreso y costos de las órdenes salen de order_financials por su cost_date (§3: la entregada, el día de la entrega;
 *   el delivery fallido, el día de la carrera, §5).
 * - Publicidad: el gasto de Meta por producto y ciudad (§62); el de campañas sin clasificar va aparte, solo en la empresa.
 * - Gastos: los de un producto o una ciudad son "otros variables atribuibles" (§30); los generales van después del
 *   margen (§29), sin repartirlos entre productos.
 * - Merma (entrega 2d, R8): lo que se da de baja después de revisarlo, al costo de su lote, también es "otros
 *   variables atribuibles" del producto y la ciudad del almacén, en la fecha de la baja (Shrinkage).
 * - Logística atribuible (§14, entrega 2e): la parte de los viajes de traslado de las unidades entregadas
 *   (order_financials.logistics_cost). El costo del producto sale de sus lotes; donde no hay lote, del catálogo.
 * Por producto, ciudad o producto + ciudad (§50 a §52); lo que no es de ninguno queda en una fila "sin asignar".
 */
class ProfitAndLoss
{
    public const GROUPS = ['product', 'city', 'product_city'];

    private const ORDER_SUMS = [
        'revenue_main', 'revenue_upsell', 'revenue_refunded', 'cogs_main', 'cogs_upsell', 'logistics_cost', 'delivery_cost', 'delivery_legacy_cost',
        'commission_seller', 'commission_upsell', 'commission_leader', 'commission_deliverer', 'cogs_missing_lines',
        'delivery_trips', 'delivery_final_trips', 'delivery_missing_trips',
    ];

    public function __construct(private ExpenseLedger $expenses, private MetaAdsData $meta, private Shrinkage $shrinkage)
    {
    }

    /**
     * @param string|null $by null (solo la empresa), product, city o product_city
     * @param array{product_id?: int|null, city_id?: int|null} $only filtrar a un producto o una ciudad
     */
    public function report(CarbonInterface $from, CarbonInterface $to, ?string $by = null, array $only = []): array
    {
        $cells = $this->cells($from, $to);
        if (array_key_exists('product_id', $only)) {
            $cells = array_filter($cells, fn ($c) => $c['product_id'] === $only['product_id']);
        }
        if (array_key_exists('city_id', $only)) {
            $cells = array_filter($cells, fn ($c) => $c['city_id'] === $only['city_id']);
        }

        $company = $this->levels($this->add(array_values($cells)));
        // Lo que solo existe a nivel empresa: publicidad sin clasificar (§62) y gastos generales (§29)
        if ($only === []) {
            $ledger = $this->expenses->forRange($from, $to);
            $unclassified = $this->unclassifiedAdSpend($from, $to);
            $company['advertising_unclassified'] = $unclassified;
            $company['advertising'] = round($company['advertising'] + $unclassified, 2);
            $company['contribution'] = round($company['contribution'] - $unclassified, 2);
            $company['fixed'] = $ledger['general'];
        } else {
            $company['advertising_unclassified'] = 0.0;
            $company['fixed'] = 0.0;
        }
        $company['operating_profit'] = round($company['contribution'] - $company['fixed'], 2);
        $company['expenses_total'] = round($company['revenue'] - $company['operating_profit'], 2);
        $company['margin_pct'] = $this->pct($company['contribution'], $company['revenue']);
        $company['operating_pct'] = $this->pct($company['operating_profit'], $company['revenue']);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'status' => $this->status($to, $company),
            'company' => $company,
            'groups' => $by ? $this->groups($cells, $by) : null,
            'warnings' => $this->warnings($from, $to, $company),
        ];
    }

    /** §58: el mismo largo, justo antes. */
    public static function previousPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    /**
     * Todo lo del período por producto + ciudad (la celda más fina): órdenes, publicidad y gastos asignados.
     * @return array<string, array>
     */
    private function cells(CarbonInterface $from, CarbonInterface $to): array
    {
        $cells = [];
        $cell = function (?int $product, ?int $city) use (&$cells): string {
            $key = ($product ?? '-') . '|' . ($city ?? '-');
            $cells[$key] ??= ['product_id' => $product, 'city_id' => $city, 'orders_delivered' => 0, 'orders_not_delivered' => 0,
                'advertising' => 0.0, 'other_variable' => 0.0, 'purchases' => 0.0, 'shrinkage' => 0.0, 'shrinkage_provisional' => 0,
                'cogs_catalog_orders' => 0] + array_fill_keys(self::ORDER_SUMS, 0.0);

            return $key;
        };

        $select = implode(', ', array_map(fn ($c) => "SUM({$c}) AS {$c}", self::ORDER_SUMS));
        $rows = DB::table('order_financials')->whereBetween('cost_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('product_id', 'city_id')
            ->selectRaw("product_id, city_id, SUM(outcome = 'delivered') AS orders_delivered, SUM(outcome = 'not_delivered') AS orders_not_delivered,
                SUM(outcome = 'delivered' AND kind <> 'return' AND COALESCE(cogs_source, 'catalog') <> 'lots') AS cogs_catalog_orders, {$select}")
            ->get();
        foreach ($rows as $r) {
            $k = $cell($r->product_id !== null ? (int) $r->product_id : null, $r->city_id !== null ? (int) $r->city_id : null);
            foreach (array_merge(self::ORDER_SUMS, ['orders_delivered', 'orders_not_delivered', 'cogs_catalog_orders']) as $c) {
                $cells[$k][$c] += (float) $r->{$c};
            }
        }
        foreach ($this->meta->dailySpend($from->toDateString(), $to->toDateString(), ['product', 'city']) as $s) {
            $k = $cell((int) $s['product_id'], (int) $s['city_id']);
            $cells[$k]['advertising'] += (float) $s['spend'];
            $cells[$k]['purchases'] += (float) ($s['purchases'] ?? 0);
        }
        foreach ($this->expenses->forRange($from, $to)['items'] as $i) {
            if ($i['scope'] === 'assigned') {
                $cells[$cell($i['product_id'], $i['city_id'])]['other_variable'] += $i['amount_usd'];
            }
        }
        foreach ($this->shrinkage->forRange($from, $to)['items'] as $i) {
            $k = $cell($i['product_id'], $i['city_id']);
            $cells[$k]['shrinkage'] += $i['amount_usd'];
            $cells[$k]['shrinkage_provisional'] += $i['provisional_units'];
        }

        return $cells;
    }

    private function add(array $cells): array
    {
        $sum = ['orders_delivered' => 0, 'orders_not_delivered' => 0, 'advertising' => 0.0, 'other_variable' => 0.0, 'purchases' => 0.0,
            'shrinkage' => 0.0, 'shrinkage_provisional' => 0, 'cogs_catalog_orders' => 0] + array_fill_keys(self::ORDER_SUMS, 0.0);
        foreach ($cells as $c) {
            foreach ($sum as $k => $v) {
                $sum[$k] += $c[$k];
            }
        }

        return $sum;
    }

    /** §47 nivel por nivel, y lo de upsells aparte (§7). */
    private function levels(array $s): array
    {
        $revenue = round($s['revenue_main'] + $s['revenue_upsell'] - $s['revenue_refunded'], 2);
        $cogs = round($s['cogs_main'] + $s['cogs_upsell'], 2);
        $logistics = round($s['logistics_cost'], 2);
        $delivery = round($s['delivery_cost'] + $s['commission_deliverer'], 2);
        $sellers = round($s['commission_seller'] + $s['commission_upsell'], 2);
        $leaders = round($s['commission_leader'], 2);
        $advertising = round($s['advertising'], 2);
        // Otros variables atribuibles: los gastos de un producto o una ciudad, más la merma
        $shrinkage = round($s['shrinkage'], 2);
        $other = round($s['other_variable'] + $s['shrinkage'], 2);
        $contribution = round($revenue - $cogs - $logistics - $delivery - $sellers - $leaders - $advertising - $other, 2);

        return [
            'revenue' => $revenue,
            'revenue_main' => round($s['revenue_main'], 2),
            'revenue_upsell' => round($s['revenue_upsell'], 2),
            'revenue_refunded' => round($s['revenue_refunded'], 2),
            'cogs' => $cogs,
            'logistics' => $logistics,
            'delivery' => $delivery,
            'delivery_legacy' => round($s['delivery_legacy_cost'], 2),
            'commission_sellers' => $sellers,
            'commission_leaders' => $leaders,
            'advertising' => $advertising,
            'other_variable' => $other,
            'shrinkage' => $shrinkage,
            'contribution' => $contribution,
            'margin_pct' => $this->pct($contribution, $revenue),
            'orders_delivered' => (int) $s['orders_delivered'],
            'orders_not_delivered' => (int) $s['orders_not_delivered'],
            'meta_purchases' => round($s['purchases'], 2),
            'upsell' => [
                'revenue' => round($s['revenue_upsell'], 2),
                'cogs' => round($s['cogs_upsell'], 2),
                'commission' => round($s['commission_upsell'], 2),
                'profit' => round($s['revenue_upsell'] - $s['cogs_upsell'] - $s['commission_upsell'], 2),
            ],
            'checks' => [
                'cogs_missing_lines' => (int) $s['cogs_missing_lines'],
                'delivery_trips' => (int) $s['delivery_trips'],
                'delivery_final_trips' => (int) $s['delivery_final_trips'],
                'delivery_missing_trips' => (int) $s['delivery_missing_trips'],
                'delivery_estimated_trips' => max(0, (int) ($s['delivery_trips'] - $s['delivery_final_trips'] - $s['delivery_missing_trips'])),
                'shrinkage_provisional_units' => (int) $s['shrinkage_provisional'],
                'cogs_catalog_orders' => (int) $s['cogs_catalog_orders'],
            ],
        ];
    }

    private function groups(array $cells, string $by): array
    {
        $keyOf = match ($by) {
            'product' => fn ($c) => (string) ($c['product_id'] ?? '-'),
            'city' => fn ($c) => (string) ($c['city_id'] ?? '-'),
            default => fn ($c) => ($c['product_id'] ?? '-') . '|' . ($c['city_id'] ?? '-'),
        };
        $buckets = [];
        foreach ($cells as $c) {
            $buckets[$keyOf($c)][] = $c;
        }
        $products = Product::whereIn('id', array_filter(array_column($cells, 'product_id')))->pluck('title', 'id');
        $cities = City::whereIn('id', array_filter(array_column($cells, 'city_id')))->pluck('name', 'id');

        $out = [];
        foreach ($buckets as $list) {
            $first = $list[0];
            $product = $by === 'city' ? null : $first['product_id'];
            $city = $by === 'product' ? null : $first['city_id'];
            $names = array_filter([
                $by !== 'city' ? ($product ? ($products[$product] ?? "Producto {$product}") : 'Sin producto') : null,
                $by !== 'product' ? ($city ? ($cities[$city] ?? "Ciudad {$city}") : 'Sin ciudad') : null,
            ]);
            $out[] = ['product_id' => $product, 'city_id' => $city, 'name' => implode(' / ', $names),
                'unassigned' => ($by !== 'city' && !$product) || ($by !== 'product' && !$city)] + $this->levels($this->add($list));
        }
        usort($out, fn ($a, $b) => [$a['unassigned'], -$a['revenue']] <=> [$b['unassigned'], -$b['revenue']]);

        return $out;
    }

    /** §62: lo que se gastó en campañas que todavía no tienen producto y ciudad. */
    private function unclassifiedAdSpend(CarbonInterface $from, CarbonInterface $to): float
    {
        return round((float) DB::table('meta_insights_daily as d')
            ->leftJoin('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')->whereBetween('d.date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('c.product_id')->orWhereNull('c.city_id'))
            ->sum('d.spend'), 2);
    }

    /**
     * §20: PROVISIONAL si hay deliveries estimados o sin costo, o si el período toca los últimos días (todavía se están
     * entregando, §57); si no, CONSOLIDADO, que no es congelado: una entrega tardía lo puede cambiar.
     */
    private function status(CarbonInterface $to, array $company): array
    {
        $lag = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $reasons = [];
        if ($to->toDateString() >= now()->subDays($lag)->toDateString()) {
            $reasons[] = "Incluye los últimos {$lag} días: todavía se están entregando órdenes.";
        }
        if ($n = $company['checks']['delivery_estimated_trips']) {
            $reasons[] = "{$n} carreras con el costo estimado por Google Routes, sin cierre aprobado.";
        }
        if ($n = $company['checks']['delivery_missing_trips']) {
            $reasons[] = "{$n} carreras sin costo calculado (cuentan $0).";
        }

        return ['label' => $reasons ? 'PROVISIONAL' : 'CONSOLIDADO', 'reasons' => $reasons];
    }

    private function warnings(CarbonInterface $from, CarbonInterface $to, array $company): array
    {
        $w = [];
        if ($n = $company['checks']['cogs_catalog_orders']) {
            $w[] = "{$n} órdenes entregadas tienen costo de catálogo (products.cost_usd) en vez del de sus lotes: salieron antes del inventario por lotes o sin stock. Su costo es provisional.";
        }
        if ($n = $company['checks']['cogs_missing_lines']) {
            $w[] = "{$n} líneas entregadas son de productos sin costo cargado: su ganancia sale más alta de lo real.";
        }
        if ($n = $company['checks']['shrinkage_provisional_units']) {
            $w[] = "{$n} pieza(s) dadas de baja no tenían costo de lote: su merma se calculó con el costo de catálogo.";
        }
        if ($company['delivery_legacy'] > 0) {
            $w[] = 'Antes del 03/10/2026 no había registro de carreras: el delivery de esas órdenes es lo que se le pagaba a la agencia por orden.';
        }
        $currencies = DB::table('meta_insights_daily as d')->join('meta_ad_accounts as a', 'a.id', '=', 'd.meta_ad_account_id')
            ->whereBetween('d.date', [$from->toDateString(), $to->toDateString()])->where('d.spend', '>', 0)
            ->distinct()->pluck('a.currency')->filter(fn ($c) => $c && strtoupper($c) !== 'USD');
        if ($currencies->isNotEmpty()) {
            $w[] = 'Hay cuentas de Meta en ' . $currencies->implode(', ') . ': su gasto se suma como si fuera USD.';
        }

        return $w;
    }

    private function pct(float $part, float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
