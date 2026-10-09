<?php

namespace App\Services\Meta;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los datos de Meta para los módulos que vienen (documento de Fran del 2026-10-08, Módulo 2, §46 a §48). No tiene
 * pantallas: el módulo financiero, el de inventario y la IA central no se hacen ahora (§59). Sale de nuestra base de
 * datos, no de Meta.
 *
 * No toca el gasto que hoy se anota a mano (product_ad_spends): el §46 dice que el módulo financiero usará este
 * "posteriormente".
 */
class MetaAdsData
{
    /**
     * §46: "gasto diario por producto y ciudad" (también por cuenta y campaña). Las campañas sin clasificar no entran:
     * no tienen producto ni ciudad (§13).
     *
     * @param array $by product, city, account, campaign
     * @return Collection<int, array{date: string, spend: float, purchases: float}>
     */
    public function dailySpend(string $from, string $to, array $by = ['product', 'city']): Collection
    {
        $columns = ['product' => 'c.product_id', 'city' => 'c.city_id', 'account' => 'd.meta_ad_account_id', 'campaign' => 'd.campaign_meta_id'];
        $groups = array_values(array_intersect_key($columns, array_flip($by)));

        $q = DB::table('meta_insights_daily as d')
            ->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')
            ->whereBetween('d.date', [$from, $to])
            ->whereNotNull('c.product_id')->whereNotNull('c.city_id')
            ->groupBy(array_merge(['d.date'], $groups))
            ->orderBy('d.date');
        $select = 'd.date, SUM(d.spend) spend, SUM(d.purchases) purchases';
        foreach ($by as $key) {
            if (isset($columns[$key])) {
                $select .= ", {$columns[$key]} {$key}_id";
            }
        }

        return $q->selectRaw($select)->get()->map(function ($r) {
            $r = (array) $r;
            $r['date'] = substr((string) $r['date'], 0, 10);
            $r['spend'] = round((float) $r['spend'], 2);
            $r['purchases'] = $r['purchases'] !== null ? (float) $r['purchases'] : null;
            return $r;
        });
    }

    /**
     * §47: lo que el inventario necesitará de un producto (y una ciudad): CPA actual, Target CPA, tendencia del CPA,
     * gasto, evolución del gasto, compras y tendencias.
     */
    public function productSnapshot(int $productId, ?int $cityId = null, string $range = 'last_7d'): array
    {
        $filters = array_filter(['product_id' => $productId, 'city_id' => $cityId]);
        $report = new MetaAdsReport($filters, $range);
        $overview = $report->overview(true);
        $product = collect($overview['products'])->firstWhere('product_id', $productId);
        $node = $cityId && $product ? collect($product['cities'])->firstWhere('city_id', $cityId) : $product;

        $series = fn (string $metric) => array_map(fn ($p) => ['date' => $p['date'], 'value' => $p['value']],
            $report->series($metric, false)['points']);

        return [
            'product_id' => $productId,
            'city_id' => $cityId,
            'from' => $report->from,
            'to' => $report->to,
            'spend' => $node['totals']['spend'] ?? 0.0,
            'purchases' => $node['totals']['purchases'] ?? null,
            'cpa' => $node['metrics']['cpa'] ?? null,
            'cpa_change' => $node['compare']['change']['cpa'] ?? null,     // tendencia del CPA contra el período anterior
            'spend_change' => $node['compare']['change']['spend'] ?? null,
            'purchases_change' => $node['compare']['change']['purchases'] ?? null,
            'target_cpa' => $product['target_cpa'] ?? null,
            'break_even_cpa' => $product['break_even_cpa'] ?? null,
            'cpa_signal' => $node['signals']['cpa'] ?? null,
            'spend_series' => $series('spend'),
            'cpa_series' => $series('cpa'),
        ];
    }
}
