<?php

namespace App\Services\Finance;

use App\Models\ProductFinancialSetting;
use App\Models\Setting;
use App\Models\TargetCpaVersion;
use App\Services\Meta\MetaAdsData;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * CPA financiero (Módulo 4 de Fran, §60 a §74), por producto + ciudad (§70, el principal) y por producto (§69):
 * - Cohorte de la ventana madura (§60): las órdenes CREADAS entre hoy − (lag + ventana) y hoy − (lag + 1); por defecto,
 *   se ignoran los últimos 4 días y se toman los 7 anteriores.
 * - R = ingreso entregado de la cohorte (principal + upsell; el upsell mejora la adquisición, §7).
 *   V = costos variables no publicitarios (§64): COGS, logística, delivery entregado y fallido, comisiones, líder y otros
 *   variables (gastos del producto, o del producto y la ciudad, en la ventana).
 *   P = Meta Purchases de esos días (§63), no pedidos entregados.
 * - Break-even = (R − V) / P (§65). Target = [(R − V) − beneficio deseado] / P (§67): beneficio deseado = R × % (sobre
 *   ventas) o COGS × % (sobre costo, interpretación nuestra del §66). Si no queda nada para publicidad: $0 y "no viable" (§68).
 * - Fully Loaded (§74, secundario): resta además los gastos generales de la ventana, repartidos según la parte de R.
 * Se calcula los miércoles con el cierre (§61) o a mano; cada cálculo es una versión nueva (§73). No toca Meta (§71).
 */
class FinancialCpa
{
    public const SETTING_WINDOW = 'finance.analysis_window_days';

    public function __construct(private MetaAdsData $meta, private ExpenseLedger $expenses, private Shrinkage $shrinkage)
    {
    }

    /** §60: [desde, hasta] de la ventana madura. */
    public static function window(?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $lag = max(0, (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4));
        $days = max(1, (int) Setting::get(self::SETTING_WINDOW, 7));
        $to = $today->copy()->subDays($lag + 1);

        return [$to->copy()->subDays($days - 1), $to];
    }

    /** @return int versiones nuevas */
    public function compute(?int $userId = null, string $trigger = 'auto', ?CarbonInterface $today = null): int
    {
        [$from, $to] = self::window($today);
        $cells = $this->cells($from, $to);
        if ($cells === []) {
            return 0;
        }
        $margins = ProductFinancialSetting::forProducts(array_values(array_unique(array_column($cells, 'product_id'))));
        $general = $this->expenses->forRange($from, $to)['general'];
        $totalRevenue = array_sum(array_column($cells, 'revenue'));

        // §69: el producto es la suma de sus ciudades
        $byProduct = [];
        foreach ($cells as $c) {
            $p = &$byProduct[$c['product_id']];
            $p ??= ['product_id' => $c['product_id'], 'city_id' => null] + array_fill_keys(array_keys(array_diff_key($c, ['product_id' => 1, 'city_id' => 1])), 0.0);
            foreach ($c as $k => $v) {
                if (!in_array($k, ['product_id', 'city_id'], true)) {
                    $p[$k] += $v;
                }
            }
            unset($p);
        }
        // Los gastos de un producto sin ciudad solo cuentan en el nivel producto
        foreach ($this->productOnlyExpenses($from, $to) as $productId => $amount) {
            if (isset($byProduct[$productId])) {
                $byProduct[$productId]['other_variable'] += $amount;
            }
        }

        $versions = DB::table('target_cpa_versions')->groupBy('product_id', 'city_id')
            ->selectRaw('product_id, city_id, MAX(version) v')->get()->mapWithKeys(fn ($r) => ["{$r->product_id}|{$r->city_id}" => (int) $r->v]);
        $now = now();
        $rows = [];
        foreach (array_merge(array_values($cells), array_values($byProduct)) as $c) {
            $m = $margins[$c['product_id']];
            $R = round($c['revenue'], 2);
            $V = round($c['variable'] + $c['other_variable'], 2);
            $P = round($c['purchases'], 2);
            $available = $R - $V;
            $desired = $m['type'] === 'cost' ? $c['cogs'] * $m['pct'] / 100 : $R * $m['pct'] / 100;
            $generalShare = $totalRevenue > 0 ? $general * $R / $totalRevenue : 0.0;
            $rows[] = [
                'product_id' => $c['product_id'],
                'city_id' => $c['city_id'],
                'version' => ($versions["{$c['product_id']}|{$c['city_id']}"] ?? 0) + 1,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'orders_created' => (int) $c['created'],
                'orders_delivered' => (int) $c['delivered'],
                'effectiveness' => $c['created'] > 0 ? round($c['delivered'] / $c['created'] * 100, 2) : null,
                'revenue' => $R,
                'variable_costs' => $V,
                'cogs' => round($c['cogs'], 2),
                'purchases' => $P,
                'spend' => round($c['spend'], 2),
                'meta_cpa' => $P > 0 ? round($c['spend'] / $P, 2) : null,
                'target_type' => $m['type'],
                'target_pct' => $m['pct'],
                'break_even' => $P > 0 ? round(max(0, $available) / $P, 2) : null,
                'target' => $P > 0 ? round(max(0, $available - $desired) / $P, 2) : null,
                'fully_loaded' => $P > 0 ? round(max(0, $available - $generalShare) / $P, 2) : null,
                'viable' => $P > 0 ? ($available - $desired) > 0 : null,
                'trigger' => $trigger,
                'computed_by' => $userId,
                'computed_at' => $now,
            ];
        }
        DB::table('target_cpa_versions')->insert($rows);

        return count($rows);
    }

    /** La última versión de cada producto + ciudad y de cada producto ("p|c" y "p|"). */
    public function latest(): array
    {
        $ids = DB::table('target_cpa_versions')->groupBy('product_id', 'city_id')->selectRaw('MAX(id) id')->pluck('id');

        return TargetCpaVersion::whereIn('id', $ids)->get()->mapWithKeys(fn ($v) => ["{$v->product_id}|{$v->city_id}" => $v])->all();
    }

    /** R, V, P y la efectividad de la cohorte, por producto + ciudad. */
    private function cells(CarbonInterface $from, CarbonInterface $to): array
    {
        $cells = [];
        $cell = function (int $product, int $city) use (&$cells): string {
            $key = "{$product}|{$city}";
            $cells[$key] ??= ['product_id' => $product, 'city_id' => $city, 'created' => 0, 'delivered' => 0, 'revenue' => 0.0, 'variable' => 0.0,
                'cogs' => 0.0, 'other_variable' => 0.0, 'purchases' => 0.0, 'spend' => 0.0];

            return $key;
        };
        $rows = DB::table('order_financials')->whereBetween('created_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('product_id')->whereNotNull('city_id')->groupBy('product_id', 'city_id')
            ->selectRaw("product_id, city_id,
                SUM(kind = 'normal') AS created, SUM(kind = 'normal' AND outcome = 'delivered') AS delivered,
                SUM(revenue_main + revenue_upsell - revenue_refunded) AS revenue,
                SUM(cogs_main + cogs_upsell) AS cogs,
                SUM(cogs_main + cogs_upsell + logistics_cost + delivery_cost + commission_deliverer + commission_seller + commission_upsell + commission_leader) AS variable")
            ->get();
        foreach ($rows as $r) {
            $k = $cell((int) $r->product_id, (int) $r->city_id);
            foreach (['created', 'delivered', 'revenue', 'cogs', 'variable'] as $f) {
                $cells[$k][$f] += (float) $r->{$f};
            }
        }
        foreach ($this->meta->dailySpend($from->toDateString(), $to->toDateString(), ['product', 'city']) as $s) {
            $k = $cell((int) $s['product_id'], (int) $s['city_id']);
            $cells[$k]['purchases'] += (float) ($s['purchases'] ?? 0);
            $cells[$k]['spend'] += (float) $s['spend'];
        }
        foreach ($this->expenses->forRange($from, $to)['items'] as $i) {
            if ($i['product_id'] && $i['city_id']) {
                $cells[$cell($i['product_id'], $i['city_id'])]['other_variable'] += $i['amount_usd'];
            }
        }
        // §64: "otros costes variables" incluye la merma de ese producto en esa ciudad (2d)
        foreach ($this->shrinkage->forRange($from, $to)['items'] as $i) {
            if ($i['city_id']) {
                $cells[$cell($i['product_id'], $i['city_id'])]['other_variable'] += $i['amount_usd'];
            }
        }

        return $cells;
    }

    private function productOnlyExpenses(CarbonInterface $from, CarbonInterface $to): array
    {
        $out = [];
        foreach ($this->expenses->forRange($from, $to)['items'] as $i) {
            if ($i['product_id'] && !$i['city_id']) {
                $out[$i['product_id']] = ($out[$i['product_id']] ?? 0) + $i['amount_usd'];
            }
        }
        // La merma de un almacén sin ciudad es solo del producto
        foreach ($this->shrinkage->forRange($from, $to)['items'] as $i) {
            if (!$i['city_id']) {
                $out[$i['product_id']] = ($out[$i['product_id']] ?? 0) + $i['amount_usd'];
            }
        }

        return $out;
    }
}
