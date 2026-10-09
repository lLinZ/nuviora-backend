<?php

namespace App\Services\Meta;

/**
 * Las fórmulas de Meta Ads (documento de Fran del 2026-10-08, Módulo 2). Siempre se calculan con los totales: al juntar
 * varios días, campañas, ad sets o anuncios no se promedian porcentajes (§33, §51). Si el divisor es 0, el resultado es
 * null y la pantalla muestra N/A (§17, §22).
 */
class MetaMetrics
{
    /** Los valores base que se suman (§50). */
    public const SUMMABLE = [
        'spend', 'impressions', 'reach', 'clicks', 'link_clicks', 'outbound_clicks', 'landing_page_views', 'purchases',
        'purchase_value', 'video_plays', 'video_3s_plays', 'thruplays', 'video_p25', 'video_p50', 'video_p75', 'video_p95',
        'video_p100', 'video_watch_time',
    ];

    /**
     * @param array $t totales: spend, impressions, reach, clicks, link_clicks, landing_page_views, purchases,
     *                 video_3s_plays, thruplays… (video_watch_time = tiempo medio × reproducciones, para promediarlo bien)
     */
    public static function compute(array $t): array
    {
        $v = fn ($k) => isset($t[$k]) && $t[$k] !== null ? (float) $t[$k] : null;
        $spend = $v('spend') ?? 0.0;
        $impressions = $v('impressions') ?? 0.0;

        return [
            // §15: CPA / Cost per Purchase
            'cpa' => self::div($spend, $v('purchases')),
            // §15: CTR (todos los clics) y Link CTR; §51: "CTR agregado = SUM(Link Clicks) / SUM(Impressions) × 100"
            'ctr' => self::pct($v('clicks'), $impressions),
            'link_ctr' => self::pct($v('link_clicks'), $impressions),
            // §15: CPC (todos los clics) y costo por clic en el enlace
            'cpc' => self::div($spend, $v('clicks')),
            'link_cpc' => self::div($spend, $v('link_clicks')),
            // §51: "CPM agregado = SUM(Spend) / SUM(Impressions) × 1000"
            'cpm' => self::div($spend * 1000, $impressions),
            'frequency' => self::div($impressions, $v('reach')),
            // §17: Landing Page View Rate = LPV / Link Clicks × 100
            'lpv_rate' => self::pct($v('landing_page_views'), $v('link_clicks')),
            // §18: Meta Landing Conversion Rate = Meta Purchases / LPV × 100
            'conversion_rate' => self::pct($v('purchases'), $v('landing_page_views')),
            // §21: Hook Rate = 3-second Video Plays / Impressions × 100
            'hook_rate' => $v('video_3s_plays') === null ? null : self::pct($v('video_3s_plays'), $impressions),
            // §22: Hold Rate = ThruPlays / 3-second Video Plays × 100
            'hold_rate' => self::pct($v('thruplays'), $v('video_3s_plays')),
            // §20: retención (vistas al 25/50/75/95/100 % sobre las reproducciones) y tiempo medio
            'retention' => $v('video_plays') ? array_map(fn ($k) => self::pct($v($k), $v('video_plays')),
                ['p25' => 'video_p25', 'p50' => 'video_p50', 'p75' => 'video_p75', 'p95' => 'video_p95', 'p100' => 'video_p100']) : null,
            'avg_watch_time' => self::div($v('video_watch_time'), $v('video_plays')),
        ];
    }

    /** Suma filas con los valores base. Un campo queda null solo si ninguna fila lo trae. */
    public static function sum(iterable $rows): array
    {
        $out = array_fill_keys(self::SUMMABLE, null);
        foreach ($rows as $row) {
            $row = (array) $row;
            if (!isset($row['video_watch_time']) && isset($row['video_avg_time'], $row['video_plays'])) {
                $row['video_watch_time'] = (float) $row['video_avg_time'] * (float) $row['video_plays'];
            }
            foreach (self::SUMMABLE as $k) {
                if (isset($row[$k]) && $row[$k] !== null) {
                    $out[$k] = ($out[$k] ?? 0) + (float) $row[$k];
                }
            }
        }
        return $out;
    }

    /** §28: variación entre períodos, en %. Sin valor anterior (o 0), no hay variación. */
    public static function change(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || $previous == 0.0) {
            return null;
        }
        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    private static function div(?float $a, ?float $b): ?float
    {
        return ($a === null || $b === null || $b == 0.0) ? null : $a / $b;
    }

    private static function pct(?float $a, ?float $b): ?float
    {
        $d = self::div($a, $b);
        return $d === null ? null : $d * 100;
    }
}
