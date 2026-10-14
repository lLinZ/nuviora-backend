<?php

namespace App\Services\Finance;

use App\Models\City;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Meta\MetaAdsData;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cohortes (Módulo 4 de Fran, §2B, §3, §21, §59): qué terminó pasando con las órdenes CREADAS cada día o cada semana.
 * Una entrega tardía actualiza su cohorte (§21). Efectividad COD = entregados / creados × 100 (§59), sin contar los
 * cambios ni las devoluciones, por día o semana y por producto + ciudad. La publicidad de una cohorte es la de sus días.
 */
class CohortReport
{
    public function __construct(private MetaAdsData $meta)
    {
    }

    /** @param string $group day o week (lunes a domingo, §54) */
    public function report(CarbonInterface $from, CarbonInterface $to, string $group = 'day', ?int $productId = null, ?int $cityId = null): array
    {
        $lag = (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4);
        $matureUntil = now()->subDays($lag + 1)->toDateString();
        $base = DB::table('order_financials')->whereBetween('created_date', [$from->toDateString(), $to->toDateString()])
            ->when($productId, fn ($q) => $q->where('product_id', $productId))->when($cityId, fn ($q) => $q->where('city_id', $cityId));
        $sums = "SUM(kind = 'normal') AS created, SUM(kind = 'normal' AND outcome = 'delivered') AS delivered,
            SUM(kind = 'normal' AND outcome = 'not_delivered') AS not_delivered, SUM(kind = 'normal' AND outcome = 'open') AS open,
            SUM(revenue_main + revenue_upsell - revenue_refunded) AS revenue,
            SUM(cogs_main + cogs_upsell + logistics_cost + delivery_cost + commission_deliverer + commission_seller + commission_upsell + commission_leader) AS variable";

        $spend = [];
        foreach ($this->meta->dailySpend($from->toDateString(), $to->toDateString(), ['product', 'city']) as $s) {
            if (($productId && (int) $s['product_id'] !== $productId) || ($cityId && (int) $s['city_id'] !== $cityId)) {
                continue;
            }
            $k = $this->bucket($s['date'], $group);
            $spend[$k]['spend'] = ($spend[$k]['spend'] ?? 0) + (float) $s['spend'];
            $spend[$k]['purchases'] = ($spend[$k]['purchases'] ?? 0) + (float) ($s['purchases'] ?? 0);
        }

        $periods = [];
        foreach ((clone $base)->groupBy('created_date')->selectRaw("created_date, {$sums}")->get() as $r) {
            $k = $this->bucket((string) $r->created_date, $group);
            $periods[$k] ??= ['period' => $k, 'created' => 0, 'delivered' => 0, 'not_delivered' => 0, 'open' => 0, 'revenue' => 0.0, 'variable' => 0.0];
            foreach (['created', 'delivered', 'not_delivered', 'open', 'revenue', 'variable'] as $f) {
                $periods[$k][$f] += (float) $r->{$f};
            }
        }
        foreach (array_keys($spend) as $k) {
            $periods[$k] ??= ['period' => $k, 'created' => 0, 'delivered' => 0, 'not_delivered' => 0, 'open' => 0, 'revenue' => 0.0, 'variable' => 0.0];
        }
        krsort($periods);
        $rows = array_map(function ($p) use ($spend, $group, $matureUntil) {
            $s = $spend[$p['period']] ?? ['spend' => 0.0, 'purchases' => 0.0];
            $contribution = round($p['revenue'] - $p['variable'], 2);
            $end = $group === 'week' ? date('Y-m-d', strtotime($p['period'] . ' +6 days')) : $p['period'];

            return [
                'period' => $p['period'], 'period_end' => $end, 'mature' => $end <= $matureUntil,
                'created' => (int) $p['created'], 'delivered' => (int) $p['delivered'], 'not_delivered' => (int) $p['not_delivered'], 'open' => (int) $p['open'],
                'effectiveness' => $p['created'] > 0 ? round($p['delivered'] / $p['created'] * 100, 1) : null,
                'revenue' => round($p['revenue'], 2), 'variable' => round($p['variable'], 2), 'contribution' => $contribution,
                'spend' => round($s['spend'], 2), 'purchases' => round($s['purchases'], 2),
                'meta_cpa' => $s['purchases'] > 0 ? round($s['spend'] / $s['purchases'], 2) : null,
                'after_ads' => round($contribution - $s['spend'], 2),
            ];
        }, array_values($periods));

        // §59: efectividad por producto + ciudad en todo el rango
        $groups = (clone $base)->whereNotNull('product_id')->groupBy('product_id', 'city_id')->selectRaw("product_id, city_id, {$sums}")->get();
        $products = Product::whereIn('id', $groups->pluck('product_id'))->pluck('title', 'id');
        $cities = City::whereIn('id', $groups->pluck('city_id')->filter())->pluck('name', 'id');
        $effectiveness = $groups->map(fn ($g) => [
            'product_id' => (int) $g->product_id, 'city_id' => $g->city_id ? (int) $g->city_id : null,
            'name' => ($products[$g->product_id] ?? "Producto {$g->product_id}") . ' / ' . ($g->city_id ? ($cities[$g->city_id] ?? "Ciudad {$g->city_id}") : 'Sin ciudad'),
            'created' => (int) $g->created, 'delivered' => (int) $g->delivered, 'open' => (int) $g->open,
            'effectiveness' => $g->created > 0 ? round($g->delivered / $g->created * 100, 1) : null,
        ])->filter(fn ($g) => $g['created'] > 0)->sortByDesc('created')->values();

        $total = ['created' => array_sum(array_column($rows, 'created')), 'delivered' => array_sum(array_column($rows, 'delivered'))];

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'group' => $group, 'mature_until' => $matureUntil,
            'rows' => $rows,
            'total' => $total + ['effectiveness' => $total['created'] > 0 ? round($total['delivered'] / $total['created'] * 100, 1) : null],
            'effectiveness' => $effectiveness,
        ];
    }

    private function bucket(string $date, string $group): string
    {
        $date = substr($date, 0, 10);

        return $group === 'week' ? date('Y-m-d', strtotime($date . ' -' . ((int) date('N', strtotime($date)) - 1) . ' days')) : $date;
    }
}
