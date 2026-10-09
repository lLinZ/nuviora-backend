<?php

use App\Models\MetaAlertRule;
use App\Services\Meta\CreativeTrackingId;
use App\Services\Meta\MetaAdsReport;
use App\Services\Meta\MetaInsightMapper;
use App\Services\Meta\MetaMetrics;
use App\Services\Meta\MetaSignals;
use App\Services\Meta\MetaVisibility;
use Illuminate\Support\Carbon;

// Módulo 2 de Fran (Meta Ads): las fórmulas y las reglas con los ejemplos del propio documento.

describe('Fórmulas (§17, §18, §21, §22, §51)', function () {
    it('LPV Rate: 1.000 clics en el enlace y 800 LPV = 80 % (§17)', function () {
        expect(MetaMetrics::compute(['link_clicks' => 1000, 'landing_page_views' => 800])['lpv_rate'])->toBe(80.0);
    });

    it('LPV Rate con 0 clics es N/A, sin dividir por cero (§17)', function () {
        expect(MetaMetrics::compute(['link_clicks' => 0, 'landing_page_views' => 5])['lpv_rate'])->toBeNull();
    });

    it('Meta Landing Conversion Rate: 800 LPV y 40 compras = 5 % (§18)', function () {
        expect(MetaMetrics::compute(['landing_page_views' => 800, 'purchases' => 40])['conversion_rate'])->toBe(5.0);
    });

    it('Hook Rate: 100.000 impresiones y 35.000 de 3 s = 35 % (§21)', function () {
        expect(MetaMetrics::compute(['impressions' => 100000, 'video_3s_plays' => 35000])['hook_rate'])->toBe(35.0);
    });

    it('Hold Rate: 35.000 de 3 s y 10.500 ThruPlays = 30 %; sin reproducciones, N/A (§22)', function () {
        expect(MetaMetrics::compute(['video_3s_plays' => 35000, 'thruplays' => 10500])['hold_rate'])->toBe(30.0);
        expect(MetaMetrics::compute(['video_3s_plays' => 0, 'thruplays' => 0])['hold_rate'])->toBeNull();
    });

    it('CPA: 470 de gasto y 100 compras = 4,70; sin compras, N/A (§50)', function () {
        expect(MetaMetrics::compute(['spend' => 470, 'purchases' => 100])['cpa'])->toBe(4.7);
        expect(MetaMetrics::compute(['spend' => 470, 'purchases' => 0])['cpa'])->toBeNull();
    });

    it('CTR: 100.000 impresiones y 2.100 clics en el enlace = 2,1 % (§50)', function () {
        expect(MetaMetrics::compute(['impressions' => 100000, 'link_clicks' => 2100])['link_ctr'])->toEqualWithDelta(2.1, 1e-9);
    });

    it('un agregado se recalcula con los totales, no promediando ratios (§33, §51)', function () {
        $a = ['spend' => 10, 'purchases' => 1, 'impressions' => 1000, 'link_clicks' => 50];  // CPA 10, CTR 5 %
        $b = ['spend' => 90, 'purchases' => 9, 'impressions' => 9000, 'link_clicks' => 90];  // CPA 10, CTR 1 %
        $m = MetaMetrics::compute(MetaMetrics::sum([$a, $b]));
        expect($m['cpa'])->toBe(10.0);
        expect($m['link_ctr'])->toEqualWithDelta(1.4, 1e-9); // 140 / 10.000, no (5 + 1) / 2 = 3
        expect($m['cpm'])->toBe(10.0);                        // 100 / 10.000 × 1000
    });

    it('el tiempo medio de video se pondera por reproducciones', function () {
        $t = MetaMetrics::sum([['video_avg_time' => 2, 'video_plays' => 100], ['video_avg_time' => 8, 'video_plays' => 300]]);
        expect(MetaMetrics::compute($t)['avg_watch_time'])->toBe(6.5);
    });

    it('variación entre períodos: $4,10 → $4,70 = +14,6 %; 2,20 % → 1,85 % = -15,9 % (§28)', function () {
        expect(MetaMetrics::change(4.70, 4.10))->toBe(14.6);
        expect(MetaMetrics::change(1.85, 2.20))->toBe(-15.9);
        expect(MetaMetrics::change(6.10, 5.20))->toBe(17.3);
        expect(MetaMetrics::change(5.0, 0.0))->toBeNull();
    });
});

describe('Señales sin IA (§37, §38, §39, §40)', function () {
    it('frecuencia en 4 tramos (§37)', function (float $f, string $level) {
        expect(MetaSignals::frequency($f)['level'])->toBe($level);
    })->with([
        [1.00, 'normal'], [1.30, 'normal'], [1.31, 'alert'], [1.60, 'alert'], [1.72, 'possible_saturation'],
        [2.00, 'possible_saturation'], [2.01, 'saturation'], [3.35, 'saturation'],
    ]);

    it('CPA contra el objetivo y el break-even, con los ejemplos del §38', function () {
        expect(MetaSignals::cpa(3.50, 4.70, null)['label'])->toBe('Dentro del objetivo');
        expect(MetaSignals::cpa(5.20, 4.70, 6.40)['label'])->toBe('Por encima del objetivo, pero todavía debajo de BE');
        expect(MetaSignals::cpa(7.10, null, 6.40)['label'])->toBe('Por encima de break-even');
        expect(MetaSignals::cpa(7.10, 4.70, 6.40)['level'])->toBe('above_break_even');
        expect(MetaSignals::cpa(null, 4.70, 6.40))->toBeNull();
    });

    $rules = fn () => collect([
        new MetaAlertRule(['type' => 'spend_no_purchases', 'threshold' => 1, 'severity' => 'alert', 'is_active' => true]),
        new MetaAlertRule(['type' => 'spend_no_purchases', 'threshold' => 2, 'severity' => 'critical', 'is_active' => true]),
        new MetaAlertRule(['type' => 'limited_sample', 'threshold' => 5, 'severity' => 'info', 'is_active' => true]),
        new MetaAlertRule(['type' => 'trend', 'metric' => 'cpa', 'direction' => 'up', 'threshold' => 10, 'severity' => 'alert', 'is_active' => true]),
        new MetaAlertRule(['type' => 'trend', 'metric' => 'ctr', 'direction' => 'down', 'threshold' => 15, 'severity' => 'alert', 'is_active' => true]),
    ]);

    it('gasto ≥ 1 Target CPA sin compras: alerta; ≥ 2: crítica (§39)', function () use ($rules) {
        $s = new MetaSignals($rules());
        expect($s->evaluate(['spend' => 4.70, 'purchases' => 0], [], null, 4.70)[0]['severity'])->toBe('alert');
        expect($s->evaluate(['spend' => 9.40, 'purchases' => 0], [], null, 4.70)[0]['severity'])->toBe('critical');
        expect($s->evaluate(['spend' => 4.00, 'purchases' => 0], [], null, 4.70))->toBe([]);
        expect($s->evaluate(['spend' => 20, 'purchases' => 0], [], null, null))->toBe([]); // sin Target CPA no hay regla
    });

    it('muestra limitada aunque haya alguna compra (§39)', function () use ($rules) {
        $s = new MetaSignals($rules());
        expect($s->evaluate(['spend' => 10, 'purchases' => 2], [], null, 4.70)[0]['type'])->toBe('limited_sample');
        expect($s->evaluate(['spend' => 30, 'purchases' => 6], [], null, 4.70))->toBe([]);
    });

    it('tendencias con el umbral configurado (§40)', function () use ($rules) {
        $s = new MetaSignals($rules());
        $signals = $s->evaluate(['spend' => 47, 'purchases' => 10], ['cpa' => 4.70, 'ctr' => 1.85], ['cpa' => 4.10, 'ctr' => 2.20], 4.70);
        expect(collect($signals)->pluck('label')->all())->toBe(['CPA aumentando +14,6 %', 'CTR disminuyendo -15,9 %']);
    });

    it('cambiar el umbral cambia la señal sin tocar código (§39)', function () {
        $s = new MetaSignals(collect([new MetaAlertRule(['type' => 'spend_no_purchases', 'threshold' => 1.5, 'severity' => 'alert'])]));
        expect($s->evaluate(['spend' => 5, 'purchases' => 0], [], null, 4))->toBe([]);
        expect($s->evaluate(['spend' => 6, 'purchases' => 0], [], null, 4))->toHaveCount(1);
    });
});

describe('Creative Tracking ID (§32, §33)', function () {
    it('detecta el código al principio del nombre', function (string $name, ?string $id) {
        expect(CreativeTrackingId::detect($name, 'name'))->toBe($id);
    })->with([
        ['C004-COMP-GINE-UGC-H1', 'C004-COMP-GINE-UGC-H1'],
        ['C004-COMP-GINE-UGC-H1 - Copia', 'C004-COMP-GINE-UGC-H1'],
        ['C004-COMP-GINE-UGC-H1 – Copy 2', 'C004-COMP-GINE-UGC-H1'],
        ['c012_flex_ugc', 'C012_FLEX_UGC'],
        ['C004 | prueba', 'C004'],
        ['CERVIMAX | CCS | TEST', null],
        ['Anuncio nuevo', null],
    ]);

    it('en modo "prefix" queda solo "C004" (§33 y §60)', function () {
        expect(CreativeTrackingId::detect('C004-COMP-GINE-UGC-H1', 'prefix'))->toBe('C004');
    });
});

describe('Métricas de Meta a valores base (§15, §20, §50)', function () {
    it('lee compras, LPV, clics salientes y video', function () {
        $m = MetaInsightMapper::map([
            'spend' => '12.50', 'impressions' => '1000', 'reach' => '800', 'clicks' => '30', 'inline_link_clicks' => '20',
            'outbound_clicks' => [['action_type' => 'outbound_click', 'value' => '18']],
            'actions' => [['action_type' => 'landing_page_view', 'value' => '16'], ['action_type' => 'omni_purchase', 'value' => '2'],
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2'], ['action_type' => 'video_view', 'value' => '300']],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => '64.5']],
            'video_play_actions' => [['action_type' => 'video_view', 'value' => '900']],
            'video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => '90']],
            'video_avg_time_watched_actions' => [['action_type' => 'video_view', 'value' => '4.5']],
        ]);
        expect($m['spend'])->toBe(12.5);
        expect($m['purchases'])->toBe(2.0);          // una vez, no omni + pixel
        expect($m['purchase_value'])->toBe(64.5);
        expect($m['landing_page_views'])->toBe(16);
        expect($m['outbound_clicks'])->toBe(18);
        expect($m['video_3s_plays'])->toBe(300);
        expect($m['thruplays'])->toBe(90);
        expect($m['video_avg_time'])->toBe(4.5);
        expect($m['actions']['purchase_type'])->toBe('omni_purchase');
    });

    it('sin acciones no inventa ceros; con acciones y sin compras, 0', function () {
        expect(MetaInsightMapper::map(['spend' => '1', 'impressions' => '10'])['purchases'])->toBeNull();
        expect(MetaInsightMapper::map(['spend' => '1', 'impressions' => '10', 'actions' => [['action_type' => 'link_click', 'value' => '1']]])['purchases'])->toBe(0.0);
        expect(MetaInsightMapper::map(['spend' => '1', 'impressions' => '10'])['video_3s_plays'])->toBeNull();
    });
});

describe('Rangos de fecha (§27, §28)', function () {
    it('calcula cada rango y el período anterior del mismo largo', function () {
        Carbon::setTestNow(Carbon::parse('2026-10-08 14:00', 'America/Caracas'));
        expect(MetaAdsReport::resolveRange('today'))->toBe(['today', '2026-10-08', '2026-10-08', '2026-10-07', '2026-10-07']);
        expect(MetaAdsReport::resolveRange('yesterday'))->toBe(['yesterday', '2026-10-07', '2026-10-07', '2026-10-06', '2026-10-06']);
        expect(MetaAdsReport::resolveRange('last_7d'))->toBe(['last_7d', '2026-10-01', '2026-10-07', '2026-09-24', '2026-09-30']);
        expect(MetaAdsReport::resolveRange('this_week_mon_today'))->toBe(['this_week_mon_today', '2026-10-05', '2026-10-08', '2026-10-01', '2026-10-04']);
        expect(MetaAdsReport::resolveRange('last_week_mon_sun'))->toBe(['last_week_mon_sun', '2026-09-28', '2026-10-04', '2026-09-21', '2026-09-27']);
        expect(MetaAdsReport::resolveRange('this_month'))->toBe(['this_month', '2026-10-01', '2026-10-08', '2026-09-23', '2026-09-30']);
        expect(MetaAdsReport::resolveRange('last_month'))->toBe(['last_month', '2026-09-01', '2026-09-30', '2026-08-02', '2026-08-31']);
        expect(MetaAdsReport::resolveRange('custom', '2026-10-01', '2026-10-04'))->toBe(['custom', '2026-10-01', '2026-10-04', '2026-09-27', '2026-09-30']);
        Carbon::setTestNow();
    });
});

describe('Datos financieros (§45)', function () {
    it('quita el gasto y el valor de las compras a quien no puede verlos, en cualquier nivel', function () {
        $data = ['products' => [['totals' => ['spend' => 10, 'purchases' => 2, 'purchase_value' => 60], 'metrics' => ['cpa' => 5],
            'compare' => ['change' => ['spend' => 3.2, 'cpa' => -1]]]]];
        $out = MetaVisibility::strip($data);
        expect($out['products'][0]['totals'])->toBe(['purchases' => 2]);
        expect($out['products'][0]['metrics'])->toBe(['cpa' => 5]); // el CPA sí lo ve el Editor (§45)
        expect($out['products'][0]['compare']['change'])->toBe(['cpa' => -1]);
    });
});
