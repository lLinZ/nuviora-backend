<?php

namespace App\Services\Meta;

use App\Models\City;
use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdset;
use App\Models\MetaCampaign;
use App\Models\MetaCreative;
use App\Models\Product;
use App\Models\ProductAdTarget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las consultas de Meta Ads (documento de Fran del 2026-10-08, Módulo 2):
 * - rangos de fecha (§27) y comparación con el período anterior (§28);
 * - filtros combinables (§30) y varias cuentas sumadas, con desglose por cuenta (§31);
 * - vista por producto → ciudad → campaña → ad set → anuncio/creativo (§29) y el creativo en todos sus anuncios (§33);
 * - las campañas sin clasificar no entran en los totales por producto y ciudad (§13);
 * - todo sale de los valores base sumados, y las fórmulas se calculan con los totales (§50, §51).
 *
 * El alcance (y por eso la frecuencia) no se puede sumar: en los rangos fijos de una sola entidad se usa el de Meta
 * (§57); en lo demás se suma y se marca como aproximado.
 */
class MetaAdsReport
{
    /** §27, con el nombre de Meta para los rangos fijos. "Últimos N días" no incluye hoy, como en Ads Manager. */
    public const RANGES = [
        'today' => 'Hoy', 'yesterday' => 'Ayer', 'last_3d' => 'Últimos 3 días', 'last_7d' => 'Últimos 7 días',
        'last_14d' => 'Últimos 14 días', 'last_30d' => 'Últimos 30 días', 'this_week_mon_today' => 'Esta semana',
        'last_week_mon_sun' => 'Semana pasada', 'this_month' => 'Este mes', 'last_month' => 'Mes pasado', 'custom' => 'Personalizado',
    ];

    private const SUMS = 'SUM(d.spend) spend, SUM(d.impressions) impressions, SUM(d.reach) reach, SUM(d.clicks) clicks,
        SUM(d.link_clicks) link_clicks, SUM(d.outbound_clicks) outbound_clicks, SUM(d.landing_page_views) landing_page_views,
        SUM(d.purchases) purchases, SUM(d.purchase_value) purchase_value, SUM(d.video_plays) video_plays,
        SUM(d.video_3s_plays) video_3s_plays, SUM(d.thruplays) thruplays, SUM(d.video_p25) video_p25, SUM(d.video_p50) video_p50,
        SUM(d.video_p75) video_p75, SUM(d.video_p95) video_p95, SUM(d.video_p100) video_p100,
        SUM(d.video_avg_time * d.video_plays) video_watch_time, COUNT(DISTINCT d.meta_id) entities';

    public string $range;
    public string $from;
    public string $to;
    public string $prevFrom;
    public string $prevTo;

    /**
     * @param array $filters product_id, city_id, account_id, campaign, adset, ad, creative (id de meta_creatives),
     *                       status (active|inactive), include_inactive (§42)
     */
    public function __construct(public array $filters = [], string $range = 'last_7d', ?string $from = null, ?string $to = null)
    {
        [$this->range, $this->from, $this->to, $this->prevFrom, $this->prevTo] = self::resolveRange($range, $from, $to);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} rango, desde, hasta y el período anterior (§28) */
    public static function resolveRange(string $range, ?string $from = null, ?string $to = null): array
    {
        $today = now(config('app.timezone'))->startOfDay();
        $range = array_key_exists($range, self::RANGES) ? $range : 'last_7d';
        [$a, $b] = match ($range) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_3d' => [$today->copy()->subDays(3), $today->copy()->subDay()],
            'last_7d' => [$today->copy()->subDays(7), $today->copy()->subDay()],
            'last_14d' => [$today->copy()->subDays(14), $today->copy()->subDay()],
            'last_30d' => [$today->copy()->subDays(30), $today->copy()->subDay()],
            'this_week_mon_today' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today],
            'last_week_mon_sun' => [$today->copy()->startOfWeek(Carbon::MONDAY)->subWeek(), $today->copy()->startOfWeek(Carbon::MONDAY)->subDay()],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom' => [self::date($from) ?? $today->copy()->subDays(7), self::date($to) ?? $today->copy()->subDay()],
        };
        if ($b->lt($a)) {
            [$a, $b] = [$b, $a];
        }
        // §28: "Últimos 7 días vs. 7 días anteriores": el mismo largo, justo antes
        $days = $a->diffInDays($b) + 1;
        $prevTo = $a->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return [$range, $a->toDateString(), $b->toDateString(), $prevFrom->toDateString(), $prevTo->toDateString()];
    }

    private static function date(?string $value): ?Carbon
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value, config('app.timezone'))->startOfDay() : null;
    }

    /** Si el rango es uno de los fijos de Meta, el alcance de una entidad sale del estado actual (§57). */
    private function presetForReach(): ?string
    {
        return in_array($this->range, MetaInsightsSync::PRESETS, true) ? $this->range : null;
    }

    private function singleDay(): bool
    {
        return $this->from === $this->to;
    }

    // ------------------------------------------------------------------ consultas base

    /** Filas del histórico de un nivel en un período, con los filtros (§30). */
    public function daily(string $level, ?string $from = null, ?string $to = null): Builder
    {
        $q = DB::table('meta_insights_daily as d')
            ->leftJoin('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', $level)
            ->whereBetween('d.date', [$from ?? $this->from, $to ?? $this->to]);
        $this->applyFilters($q, $level);
        return $q;
    }

    private function applyFilters(Builder $q, string $level): void
    {
        $f = $this->filters;
        $q->when(!empty($f['product_id']), fn ($q) => $q->where('c.product_id', $f['product_id']))
            ->when(!empty($f['city_id']), fn ($q) => $q->where('c.city_id', $f['city_id']))
            ->when(!empty($f['account_id']), fn ($q) => $q->where('d.meta_ad_account_id', $f['account_id']))
            ->when(!empty($f['campaign']), fn ($q) => $q->where('d.campaign_meta_id', $f['campaign']))
            ->when(!empty($f['adset']) && $level !== 'campaign', fn ($q) => $q->where('d.adset_meta_id', $f['adset']))
            ->when(!empty($f['ad']) && $level === 'ad', fn ($q) => $q->where('d.ad_meta_id', $f['ad']));
        if (!empty($f['creative']) && $level === 'ad') {
            $q->whereIn('d.ad_meta_id', MetaAd::where('meta_creative_ref_id', $f['creative'])->select('meta_id'));
        }
    }

    /** Solo las campañas clasificadas, o solo las que faltan (§13). */
    private function classified(Builder $q, bool $yes): Builder
    {
        return $yes
            ? $q->whereNotNull('c.product_id')->whereNotNull('c.city_id')
            : $q->where(fn ($w) => $w->whereNull('c.product_id')->orWhereNull('c.city_id'));
    }

    /** El nivel con el que se suman los totales: el más alto que respeta los filtros. */
    private function totalsLevel(): string
    {
        if (!empty($this->filters['ad']) || !empty($this->filters['creative'])) {
            return 'ad';
        }
        return !empty($this->filters['adset']) ? 'adset' : 'campaign';
    }

    /**
     * Suma por grupos. $groups son columnas ya calificadas (c.product_id, d.meta_ad_account_id…).
     *
     * @return Collection<string, array> clave "a|b|…" => totales
     */
    private function sums(string $level, array $groups, ?bool $classified, ?string $from = null, ?string $to = null): Collection
    {
        $q = $this->daily($level, $from, $to);
        if ($classified !== null) {
            $this->classified($q, $classified);
        }
        $select = self::SUMS;
        foreach ($groups as $i => $g) {
            $select .= ", {$g} g{$i}";
        }
        $rows = $q->selectRaw($select)->when($groups, fn ($q) => $q->groupBy($groups))->get();

        return $rows->mapWithKeys(function ($r) use ($groups) {
            $r = (array) $r;
            $key = implode('|', array_map(fn ($i) => (string) $r["g{$i}"], array_keys($groups)));
            return [$key => $r];
        });
    }

    /** El alcance de Meta para el rango fijo (§57), sumado por grupos de campañas, ad sets o anuncios. */
    private function presetReach(string $level, array $groups, ?bool $classified): ?Collection
    {
        $preset = $this->presetForReach();
        if (!$preset) {
            return null;
        }
        $q = DB::table('meta_insights_current as d')
            ->leftJoin('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', $level)->where('d.range', $preset);
        $this->applyFilters($q, $level);
        if ($classified !== null) {
            $this->classified($q, $classified);
        }
        $select = 'SUM(d.reach) reach';
        foreach ($groups as $i => $g) {
            $select .= ", {$g} g{$i}";
        }
        return $q->selectRaw($select)->when($groups, fn ($q) => $q->groupBy($groups))->get()
            ->mapWithKeys(function ($r) use ($groups) {
                $r = (array) $r;
                return [implode('|', array_map(fn ($i) => (string) $r["g{$i}"], array_keys($groups))) => $r['reach'] !== null ? (float) $r['reach'] : null];
            });
    }

    /** Totales + fórmulas de un grupo, con el alcance de Meta si se puede. */
    private function pack(?array $sum, ?float $presetReach): array
    {
        $totals = MetaMetrics::sum($sum ? [$sum] : []);
        $entities = (int) ($sum['entities'] ?? 0);
        $exact = $entities <= 1 && ($this->singleDay() || $presetReach !== null);
        // La del período anterior se calcula con el alcance sumado día a día: para comparar, las dos con la misma base
        $dailyFrequency = MetaMetrics::compute($totals)['frequency'];
        if ($presetReach !== null) {
            $totals['reach'] = $presetReach;
        }
        return ['totals' => $totals, 'metrics' => MetaMetrics::compute($totals), 'frequency_exact' => $exact, 'frequency_daily' => $dailyFrequency];
    }

    // ------------------------------------------------------------------ vistas

    /**
     * §29 y §60: por producto → ciudad, con el desglose por cuenta (§31), más lo que falta clasificar (§13).
     */
    public function overview(bool $compare): array
    {
        $groups = ['c.product_id', 'c.city_id', 'd.meta_ad_account_id'];
        $cur = $this->sums('campaign', $groups, true);
        $reach = $this->presetReach('campaign', $groups, true);
        $prev = $compare ? $this->sums('campaign', $groups, true, $this->prevFrom, $this->prevTo) : collect();

        $products = Product::whereIn('id', $cur->keys()->merge($prev->keys())->map(fn ($k) => (int) explode('|', $k)[0])->unique())
            ->get(['id', 'title', 'showable_name'])->keyBy('id');
        $cities = City::get(['id', 'name'])->keyBy('id');
        $accounts = MetaAdAccount::get(['id', 'name'])->keyBy('id');
        $targets = $this->targets($products->keys()->all(), $this->to);

        $tree = [];
        foreach ($cur->keys()->merge($prev->keys())->unique() as $key) {
            [$p, $c, $a] = explode('|', $key);
            $tree[$p][$c][$a] = $key;
        }

        $out = [];
        foreach ($tree as $productId => $byCity) {
            $target = $targets[$productId] ?? ['target_cpa' => null, 'break_even_cpa' => null];
            $pKeys = collect($byCity)->flatten()->all();
            $product = $this->node($cur, $prev, $reach, $pKeys, $compare, $target['target_cpa'], $target['break_even_cpa']);
            $product += [
                'product_id' => (int) $productId,
                'name' => ($pr = $products->get((int) $productId)) ? ($pr->showable_name ?: $pr->title) : "Producto {$productId}",
                'target_cpa' => $target['target_cpa'],
                'break_even_cpa' => $target['break_even_cpa'],
                'cities' => [],
            ];
            foreach ($byCity as $cityId => $byAccount) {
                $city = $this->node($cur, $prev, $reach, array_values($byAccount), $compare, $target['target_cpa'], $target['break_even_cpa']);
                $city += ['city_id' => (int) $cityId, 'name' => $cities->get((int) $cityId)?->name ?? "Ciudad {$cityId}", 'accounts' => []];
                foreach ($byAccount as $accountId => $key) {
                    $acc = $this->node($cur, $prev, $reach, [$key], $compare, $target['target_cpa'], $target['break_even_cpa']);
                    $city['accounts'][] = $acc + ['account_id' => (int) $accountId, 'name' => $accounts->get((int) $accountId)?->name];
                }
                $product['cities'][] = $city;
            }
            usort($product['cities'], fn ($x, $y) => ($y['totals']['spend'] ?? 0) <=> ($x['totals']['spend'] ?? 0));
            $out[] = $product;
        }
        usort($out, fn ($x, $y) => ($y['totals']['spend'] ?? 0) <=> ($x['totals']['spend'] ?? 0));

        $un = $this->sums('campaign', [], false)->first();
        $unPrev = $compare ? $this->sums('campaign', [], false, $this->prevFrom, $this->prevTo)->first() : null;
        $unclassified = $this->pack($un, null) + ['campaigns' => (int) ($un['entities'] ?? 0)];
        if ($compare) {
            $unclassified['compare'] = $this->compareBlock($unclassified, $unPrev);
        }
        $unclassified['signals'] = $this->signals($unclassified, null, null);

        return ['products' => $out, 'unclassified' => $unclassified];
    }

    /** Un nodo del árbol (producto, ciudad o cuenta): la suma de sus claves, con comparación y señales. */
    private function node(Collection $cur, Collection $prev, ?Collection $reach, array $keys, bool $compare, ?float $target, ?float $breakEven): array
    {
        $sum = MetaMetrics::sum(collect($keys)->map(fn ($k) => $cur->get($k))->filter()->all());
        $entities = collect($keys)->sum(fn ($k) => (int) ($cur->get($k)['entities'] ?? 0));
        $presetReach = $reach ? collect($keys)->sum(fn ($k) => (float) ($reach->get($k) ?? 0)) : null;
        $packed = $this->pack($sum + ['entities' => $entities], $presetReach ?: null);
        if ($compare) {
            $prevSum = MetaMetrics::sum(collect($keys)->map(fn ($k) => $prev->get($k))->filter()->all());
            $packed['compare'] = $this->compareBlock($packed, $prevSum);
        }
        $packed['signals'] = $this->signals($packed, $target, $breakEven);
        return $packed;
    }

    /** §28: el período anterior y la variación de cada métrica. */
    private function compareBlock(array $packed, ?array $prevSum): array
    {
        $metrics = $packed['metrics'];
        $totals = $packed['totals'];
        $prevTotals = MetaMetrics::sum($prevSum ? [$prevSum] : []);
        $prevMetrics = MetaMetrics::compute($prevTotals);
        $change = [];
        foreach (['cpa', 'ctr', 'link_ctr', 'cpc', 'link_cpc', 'cpm', 'lpv_rate', 'conversion_rate', 'hook_rate', 'hold_rate'] as $m) {
            $change[$m] = MetaMetrics::change($metrics[$m], $prevMetrics[$m]);
        }
        $change['frequency'] = MetaMetrics::change($packed['frequency_daily'] ?? $metrics['frequency'], $prevMetrics['frequency']);
        foreach (['spend', 'purchases', 'impressions', 'link_clicks', 'landing_page_views'] as $m) {
            $change[$m] = MetaMetrics::change($totals[$m] !== null ? (float) $totals[$m] : null, $prevTotals[$m] !== null ? (float) $prevTotals[$m] : null);
        }
        return ['from' => $this->prevFrom, 'to' => $this->prevTo, 'totals' => $prevTotals, 'metrics' => $prevMetrics, 'change' => $change];
    }

    private function signals(array $packed, ?float $target, ?float $breakEven): array
    {
        static $engine;
        $engine ??= new MetaSignals();
        return [
            'frequency' => MetaSignals::frequency($packed['metrics']['frequency']),
            'cpa' => MetaSignals::cpa($packed['metrics']['cpa'], $target, $breakEven),
            // las tendencias comparan la frecuencia con la misma base que el período anterior
            'rules' => $engine->evaluate($packed['totals'], ['frequency' => $packed['frequency_daily'] ?? $packed['metrics']['frequency']] + $packed['metrics'], $packed['compare']['metrics'] ?? null, $target),
        ];
    }

    /** Target CPA y Break-even vigentes en una fecha, por producto (§34, §49). */
    public function targets(array $productIds, string $date): array
    {
        if (!$productIds) {
            return [];
        }
        return ProductAdTarget::whereIn('product_id', $productIds)->where('valid_from', '<=', $date)
            ->orderBy('valid_from')->orderBy('id')->get()
            ->keyBy('product_id') // se queda la última vigente
            ->map(fn ($t) => [
                'target_cpa' => $t->target_cpa !== null ? (float) $t->target_cpa : null,
                'break_even_cpa' => $t->break_even_cpa !== null ? (float) $t->break_even_cpa : null,
            ])->all();
    }

    /**
     * §29: las campañas, ad sets, anuncios o creativos de un filtro. Lo inactivo se oculta salvo que se pida (§42).
     */
    public function rows(string $level, bool $compare): array
    {
        $includeInactive = !empty($this->filters['include_inactive']);
        $status = $this->filters['status'] ?? null;

        if ($level === 'creative') {
            return $this->creativeRows($compare);
        }

        $model = ['campaign' => MetaCampaign::class, 'adset' => MetaAdset::class, 'ad' => MetaAd::class][$level];
        $entities = $model::query()
            ->when($level !== 'campaign', fn ($q) => $q->with('campaign:id,meta_id,name,product_id,city_id'))
            ->when($level === 'campaign', fn ($q) => $q->with(['product:id,title,showable_name', 'city:id,name']))
            ->when($level === 'ad', fn ($q) => $q->with('creative:id,tracking_id,status,type'))
            ->with('account:id,name')
            ->when(!empty($this->filters['account_id']), fn ($q) => $q->where('meta_ad_account_id', $this->filters['account_id']))
            ->when($level === 'campaign' && !empty($this->filters['campaign']), fn ($q) => $q->where('meta_id', $this->filters['campaign']))
            ->when($level !== 'campaign' && !empty($this->filters['campaign']), fn ($q) => $q->where('campaign_meta_id', $this->filters['campaign']))
            ->when($level === 'ad' && !empty($this->filters['adset']), fn ($q) => $q->where('adset_meta_id', $this->filters['adset']))
            ->when($level === 'ad' && !empty($this->filters['creative']), fn ($q) => $q->where('meta_creative_ref_id', $this->filters['creative']))
            ->when(!empty($this->filters['product_id']) || !empty($this->filters['city_id']), function ($q) use ($level) {
                $scope = fn ($c) => $c->when(!empty($this->filters['product_id']), fn ($w) => $w->where('product_id', $this->filters['product_id']))
                    ->when(!empty($this->filters['city_id']), fn ($w) => $w->where('city_id', $this->filters['city_id']));
                $level === 'campaign' ? $scope($q) : $q->whereHas('campaign', $scope);
            })
            ->get();

        $cur = $this->sums($level, ['d.meta_id'], null);
        $prev = $compare ? $this->sums($level, ['d.meta_id'], null, $this->prevFrom, $this->prevTo) : collect();
        $reach = $this->presetReach($level, ['d.meta_id'], null);
        $productIds = $level === 'campaign' ? $entities->pluck('product_id') : $entities->pluck('campaign.product_id');
        $targets = $this->targets($productIds->filter()->unique()->values()->all(), $this->to);

        $rows = [];
        foreach ($entities as $e) {
            $active = $e->missing_since === null && $e->effective_status === 'ACTIVE';
            $hasData = $cur->has($e->meta_id);
            if ($status === 'active' && !$active) continue;
            if ($status === 'inactive' && $active) continue;
            if (!$active && !$includeInactive && $status !== 'inactive') continue;

            $campaign = $level === 'campaign' ? $e : $e->campaign;
            $target = $targets[$campaign?->product_id] ?? ['target_cpa' => null, 'break_even_cpa' => null];
            $packed = $this->pack($cur->get($e->meta_id), $reach?->get($e->meta_id));
            if ($compare) {
                $packed['compare'] = $this->compareBlock($packed, $prev->get($e->meta_id));
            }
            $packed['signals'] = $this->signals($packed, $target['target_cpa'], $target['break_even_cpa']);

            $row = $packed + [
                'level' => $level,
                'id' => $e->id,
                'meta_id' => $e->meta_id,
                'name' => $e->name,
                'account' => $e->account?->name,
                'effective_status' => $e->effective_status,
                'active' => $active,
                'missing_since' => $e->missing_since?->toIso8601String(),
                'last_known_state' => $e->last_known_state,
                'has_data' => $hasData,
                'target_cpa' => $target['target_cpa'],
                'break_even_cpa' => $target['break_even_cpa'],
            ];
            if ($level === 'campaign') {
                $row += [
                    'product_id' => $e->product_id, 'city_id' => $e->city_id,
                    'product' => $e->product ? ($e->product->showable_name ?: $e->product->title) : null,
                    'city' => $e->city?->name, 'objective' => $e->objective,
                ];
            } else {
                $row += ['campaign_meta_id' => $e->campaign_meta_id, 'campaign' => $e->campaign?->name];
            }
            if ($level === 'adset') {
                $row['attribution_spec'] = $e->attribution_spec;
            }
            if ($level === 'ad') {
                $row += [
                    'adset_meta_id' => $e->adset_meta_id,
                    'creative_tracking_id' => $e->creative_tracking_id,
                    'tracking_id_source' => $e->tracking_id_source,
                    'creative_id' => $e->meta_creative_ref_id,
                    'creative_status' => $e->creative?->status,
                    'creative_type' => $e->creative_type,
                    'thumbnail_url' => $e->thumbnail_url,
                ];
            }
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => ($b['totals']['spend'] ?? 0) <=> ($a['totals']['spend'] ?? 0));
        return $rows;
    }

    /** §33 B: cada creativo con los totales de todos sus anuncios, de cualquier campaña, ad set o cuenta. */
    private function creativeRows(bool $compare): array
    {
        $group = ['a.meta_creative_ref_id'];
        $sum = function (?string $from, ?string $to) use ($group) {
            $q = $this->daily('ad', $from, $to)->join('meta_ads as a', 'a.meta_id', '=', 'd.ad_meta_id')->whereNotNull('a.meta_creative_ref_id');
            return $q->selectRaw(self::SUMS . ', a.meta_creative_ref_id g0, COUNT(DISTINCT a.meta_ad_account_id) accounts')
                ->groupBy($group)->get()->mapWithKeys(fn ($r) => [(string) $r->g0 => (array) $r]);
        };
        $cur = $sum(null, null);
        $prev = $compare ? $sum($this->prevFrom, $this->prevTo) : collect();

        $creatives = MetaCreative::withCount('ads')->whereIn('id', $cur->keys()->merge($prev->keys())->unique())->get();
        $productOf = $this->creativeProducts($creatives->pluck('id')->all());
        $targets = $this->targets(collect($productOf)->filter()->unique()->values()->all(), $this->to);

        $rows = [];
        foreach ($creatives as $cr) {
            if (!empty($this->filters['status_creative']) && $cr->status !== $this->filters['status_creative']) {
                continue;
            }
            $target = $targets[$productOf[$cr->id] ?? 0] ?? ['target_cpa' => null, 'break_even_cpa' => null];
            $packed = $this->pack($cur->get((string) $cr->id), null);
            if ($compare) {
                $packed['compare'] = $this->compareBlock($packed, $prev->get((string) $cr->id));
            }
            $packed['signals'] = $this->signals($packed, $target['target_cpa'], $target['break_even_cpa']);
            $rows[] = $packed + [
                'level' => 'creative', 'id' => $cr->id, 'tracking_id' => $cr->tracking_id, 'name' => $cr->tracking_id,
                'type' => $cr->type, 'status' => $cr->status, 'ads' => $cr->ads_count,
                'accounts' => (int) ($cur->get((string) $cr->id)['accounts'] ?? 0),
                'target_cpa' => $target['target_cpa'], 'break_even_cpa' => $target['break_even_cpa'],
            ];
        }
        usort($rows, fn ($a, $b) => ($b['totals']['spend'] ?? 0) <=> ($a['totals']['spend'] ?? 0));
        return $rows;
    }

    /** El producto de cada creativo, si todos sus anuncios están en campañas del mismo producto. */
    private function creativeProducts(array $creativeIds): array
    {
        if (!$creativeIds) {
            return [];
        }
        return DB::table('meta_ads as a')->join('meta_campaigns as c', 'c.meta_id', '=', 'a.campaign_meta_id')
            ->whereIn('a.meta_creative_ref_id', $creativeIds)->whereNotNull('c.product_id')
            ->groupBy('a.meta_creative_ref_id')
            ->selectRaw('a.meta_creative_ref_id id, MIN(c.product_id) p, COUNT(DISTINCT c.product_id) n')
            ->get()->mapWithKeys(fn ($r) => [$r->id => $r->n == 1 ? (int) $r->p : null])->all();
    }

    /**
     * §26: la evolución de una métrica día por día, para lo que diga el filtro. Con el Target CPA y el Break-even de
     * cada día (§34, §49) si hay un solo producto.
     */
    public function series(string $metric, bool $compare): array
    {
        $level = $this->totalsLevel();
        // Una entidad concreta (campaña, ad set, anuncio, creativo) se muestra aunque no esté clasificada.
        $concrete = !empty($this->filters['campaign']) || !empty($this->filters['adset']) || !empty($this->filters['ad']) || !empty($this->filters['creative']);
        $fetch = function (string $from, string $to) use ($level, $concrete) {
            $q = $this->daily($level, $from, $to);
            if (!$concrete) {
                $this->classified($q, true);
            }
            return $q->selectRaw(self::SUMS . ', d.date g0')->groupBy('d.date')->orderBy('d.date')->get()
                ->mapWithKeys(fn ($r) => [Carbon::parse($r->g0)->toDateString() => (array) $r]);
        };
        $cur = $fetch($this->from, $this->to);
        $prev = $compare ? $fetch($this->prevFrom, $this->prevTo) : collect();

        $productId = $this->filters['product_id'] ?? null;
        if (!$productId && !empty($this->filters['campaign'])) {
            $productId = MetaCampaign::where('meta_id', $this->filters['campaign'])->value('product_id');
        }
        $targetRows = $productId ? ProductAdTarget::where('product_id', $productId)->orderBy('valid_from')->orderBy('id')->get() : collect();

        $points = [];
        $offset = 0;
        for ($d = Carbon::parse($this->from); $d->lte(Carbon::parse($this->to)); $d->addDay(), $offset++) {
            $day = $d->toDateString();
            $totals = MetaMetrics::sum($cur->has($day) ? [$cur->get($day)] : []);
            $value = $this->metricValue($metric, $totals);
            $prevDay = Carbon::parse($this->prevFrom)->addDays($offset)->toDateString();
            $target = $targetRows->filter(fn ($t) => $t->valid_from->toDateString() <= $day)->last();
            $points[] = [
                'date' => $day,
                'value' => $value,
                'previous' => $compare ? $this->metricValue($metric, MetaMetrics::sum($prev->has($prevDay) ? [$prev->get($prevDay)] : [])) : null,
                'previous_date' => $compare ? $prevDay : null,
                'target_cpa' => $target?->target_cpa !== null ? (float) $target->target_cpa : null,
                'break_even_cpa' => $target?->break_even_cpa !== null ? (float) $target->break_even_cpa : null,
            ];
        }
        return ['metric' => $metric, 'level' => $level, 'product_id' => $productId, 'points' => $points];
    }

    private function metricValue(string $metric, array $totals): ?float
    {
        if (in_array($metric, ['spend', 'purchases', 'impressions', 'reach', 'link_clicks', 'landing_page_views', 'video_3s_plays', 'thruplays'], true)) {
            return $totals[$metric] !== null ? (float) $totals[$metric] : null;
        }
        $m = MetaMetrics::compute($totals);
        return array_key_exists($metric, $m) && !is_array($m[$metric]) ? $m[$metric] : null;
    }

    public static function rangeLabel(string $range): string
    {
        return self::RANGES[$range] ?? $range;
    }
}
