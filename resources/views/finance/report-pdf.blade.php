@php
    // Reporte ejecutivo visual (Módulo 4 de Fran, §80). $r es el contenido guardado de la versión.
    $c = $r['company'] ?? [];
    $p = $r['previous']['company'] ?? [];
    $cash = $r['cash'] ?? [];
    $money = function ($v) {
        if ($v === null) return '—';
        $v = (float) $v;
        return ($v < 0 ? '−$' : '$') . number_format(abs($v), 2, ',', '.');
    };
    $bs = fn ($v, $cur) => $cur === 'VES' ? 'Bs ' . number_format((float) $v, 2, ',', '.') : ($cur === 'USDT' ? number_format((float) $v, 2, ',', '.') . ' USDT' : $money($v));
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %';
    // Los títulos de Shopify son largos ("Audinet® + Regalo Exclusivo. Escucha claro…"): se cortan
    $short = fn ($s, int $max = 42) => mb_strimwidth((string) $s, 0, $max, '…');
    $day = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
    // La diferencia con el período anterior; "good" dice si subir es bueno
    $delta = function ($now, $before, bool $upIsGood = true) use ($money) {
        if ($now === null || $before === null) return '';
        $d = (float) $now - (float) $before;
        if (abs($d) < 0.005) return '<span class="muted">igual</span>';
        $better = $upIsGood ? $d > 0 : $d < 0;
        $rel = (float) $before != 0.0 ? ' (' . ($d > 0 ? '+' : '') . number_format($d / abs((float) $before) * 100, 0, ',', '.') . ' %)' : '';
        return '<span class="' . ($better ? 'good' : 'bad') . '">' . ($d > 0 ? '+' : '−') . substr($money(abs($d)), 0) . $rel . '</span>';
    };
    $status = $r['status']['label'] ?? $version->status;
    $levels = [
        ['Ingresos', 'revenue', true, false],
        ['− COGS', 'cogs', false, false],
        ['− Logística atribuible', 'logistics', false, false],
        ['− Delivery', 'delivery', false, false],
        ['− Comisiones vendedoras', 'commission_sellers', false, false],
        ['− Comisión líderes', 'commission_leaders', false, false],
        ['− Publicidad', 'advertising', false, false],
        ['− Otros costes variables', 'other_variable', false, false],
        ['= Margen de contribución', 'contribution', true, true],
        ['− Gastos generales', 'fixed', false, false],
        ['= Beneficio operativo', 'operating_profit', true, true],
    ];
    $products = array_values(array_filter($r['by_product'] ?? [], fn ($g) => ($g['revenue'] ?? 0) != 0 || ($g['contribution'] ?? 0) != 0));
    $maxContribution = max(1, ...array_map(fn ($g) => abs((float) $g['contribution']), $products ?: [['contribution' => 1]]));
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $report->title() }}</title>
<style>
    @page { margin: 22mm 14mm 18mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; color: #1f2937; }
    h1 { font-size: 15pt; margin: 0; color: #fff; }
    h2 { font-size: 10.5pt; margin: 14px 0 6px; color: #1f2a44; border-bottom: 1.5px solid #1f2a44; padding-bottom: 3px; }
    .header { position: fixed; top: -16mm; left: 0; right: 0; height: 10mm; font-size: 7.5pt; color: #6b7280; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; font-size: 7.5pt; color: #6b7280; }
    .band { background: #1f2a44; color: #fff; padding: 12px 14px; border-radius: 6px; }
    .band .sub { color: #cbd5e1; margin-top: 4px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 7.5pt; font-weight: bold; }
    .PROVISIONAL { background: #fef3c7; color: #92400e; }
    .CONSOLIDADO { background: #dcfce7; color: #166534; }
    table { width: 100%; border-collapse: collapse; }
    .kpis td { width: 25%; padding: 4px; vertical-align: top; }
    .kpi { border: 1px solid #e5e7eb; border-radius: 5px; padding: 7px 8px; }
    .kpi .label { color: #6b7280; font-size: 7.5pt; }
    .kpi .value { font-size: 12.5pt; font-weight: bold; margin: 2px 0; }
    .grid th { text-align: left; font-size: 7.5pt; color: #6b7280; font-weight: normal; border-bottom: 1px solid #d1d5db; padding: 3px 4px; }
    .grid td { padding: 3px 4px; border-bottom: 1px solid #f1f5f9; }
    .num { text-align: right; white-space: nowrap; }
    .total td { font-weight: bold; background: #f8fafc; border-top: 1px solid #cbd5e1; }
    .good { color: #15803d; }
    .bad { color: #b91c1c; }
    .muted { color: #6b7280; }
    .bar { height: 7px; background: #2563eb; border-radius: 2px; }
    .bar.neg { background: #dc2626; }
    .note { background: #f8fafc; border-left: 3px solid #94a3b8; padding: 5px 8px; margin: 3px 0; }
    .warn { border-left-color: #d97706; background: #fffbeb; }
    .changes { border-left-color: #2563eb; background: #eff6ff; }
    .two td.col { width: 50%; vertical-align: top; padding-right: 8px; }
    .avoid { page-break-inside: avoid; }
</style>
</head>
<body>
<div class="header">Nuviora · Reporte financiero · {{ $report->title() }} · versión {{ $version->version }}</div>
<div class="footer">Generado el {{ $version->generated_at->format('d/m/Y H:i') }} · Solo administración</div>

<div class="band">
    <h1>{{ $report->title() }}</h1>
    <div class="sub">
        Del {{ $day($r['from'] ?? null) }} al {{ $day($r['to'] ?? null) }} ({{ $r['days'] ?? 1 }} día{{ ($r['days'] ?? 1) == 1 ? '' : 's' }})
        · comparado con el {{ $day($r['previous']['from'] ?? null) }} al {{ $day($r['previous']['to'] ?? null) }}
        · versión {{ $version->version }}
    </div>
    <div style="margin-top: 6px;"><span class="badge {{ $status }}">{{ $status }}</span>
        <span class="sub">&nbsp;{{ $c['orders_delivered'] ?? 0 }} entregadas · {{ $c['orders_not_delivered'] ?? 0 }} no entregadas con costo</span></div>
</div>

@if (!empty($version->changes))
    <div class="note changes" style="margin-top: 8px;">
        <b>Qué cambió desde la versión {{ $version->version - 1 }}</b> ({{ $version->reason }}):
        @foreach ($version->changes as $ch)
            {{ $ch['label'] }}: {{ $ch['key'] === 'status' ? $ch['before'] : (in_array($ch['key'], ['orders_delivered', 'orders_not_delivered']) ? $ch['before'] : $money($ch['before'])) }}
            → {{ $ch['key'] === 'status' ? $ch['after'] : (in_array($ch['key'], ['orders_delivered', 'orders_not_delivered']) ? $ch['after'] : $money($ch['after'])) }}@if (!$loop->last); @endif
        @endforeach
    </div>
@endif

<h2>Rentabilidad</h2>
<table class="kpis">
    <tr>
        @foreach ([['Ingresos', 'revenue', true], ['Beneficio operativo', 'operating_profit', true], ['Publicidad', 'advertising', false], ['COGS', 'cogs', false]] as [$label, $key, $up])
            <td><div class="kpi"><div class="label">{{ $label }}</div>
                <div class="value {{ $key === 'operating_profit' && ($c[$key] ?? 0) < 0 ? 'bad' : '' }}">{{ $money($c[$key] ?? 0) }}</div>
                <div>{!! $delta($c[$key] ?? null, $p[$key] ?? null, $up) !!}</div></div></td>
        @endforeach
    </tr>
    <tr>
        <td><div class="kpi"><div class="label">Margen de contribución</div><div class="value">{{ $pct($c['margin_pct'] ?? null) }}</div>
            <div class="muted">antes: {{ $pct($p['margin_pct'] ?? null) }}</div></div></td>
        @foreach ([['Delivery', 'delivery', false], ['Comisiones', null, false]] as [$label, $key, $up])
            @php
                $now = $key ? ($c[$key] ?? 0) : ($c['commission_sellers'] ?? 0) + ($c['commission_leaders'] ?? 0);
                $before = $key ? ($p[$key] ?? null) : (isset($p['commission_sellers']) ? $p['commission_sellers'] + ($p['commission_leaders'] ?? 0) : null);
            @endphp
            <td><div class="kpi"><div class="label">{{ $label }}</div><div class="value">{{ $money($now) }}</div><div>{!! $delta($now, $before, $up) !!}</div></div></td>
        @endforeach
        <td><div class="kpi"><div class="label">Caja disponible</div><div class="value">≈ {{ $money($cash['available_usd'] ?? null) }}</div>
            <div class="muted">al {{ \Illuminate\Support\Carbon::parse($r['generated_at'] ?? now())->format('d/m H:i') }}</div></div></td>
    </tr>
</table>

<table class="grid avoid" style="margin-top: 6px;">
    <tr><th>Concepto (§47)</th><th class="num">Este período</th><th class="num">Período anterior</th><th class="num">Diferencia</th></tr>
    @foreach ($levels as [$label, $key, $up, $bold])
        <tr class="{{ $bold ? 'total' : '' }}">
            <td>{{ $label }}</td>
            <td class="num">{{ $money($c[$key] ?? 0) }}</td>
            <td class="num muted">{{ $money($p[$key] ?? 0) }}</td>
            <td class="num">{!! $delta($c[$key] ?? null, $p[$key] ?? null, $up) !!}</td>
        </tr>
    @endforeach
</table>
@if (!empty($c['upsell']))
    <p class="muted" style="margin: 5px 0 0;">Upsells: ingresos {{ $money($c['upsell']['revenue']) }}, costo {{ $money($c['upsell']['cogs']) }},
        comisión {{ $money($c['upsell']['commission']) }} → beneficio de upsells <b>{{ $money($c['upsell']['profit']) }}</b>.
        @if (($c['advertising_unclassified'] ?? 0) > 0) Publicidad sin clasificar: {{ $money($c['advertising_unclassified']) }}. @endif</p>
@endif

<h2>Por producto</h2>
<table class="grid">
    <tr><th style="width: 34%">Producto</th><th class="num">Entregadas</th><th class="num">Ingresos</th><th class="num">Publicidad</th><th class="num">Margen de contribución</th><th class="num">%</th><th style="width: 16%"></th></tr>
    @forelse (array_slice($products, 0, 15) as $g)
        <tr>
            <td>{{ $short($g['name']) }}</td>
            <td class="num">{{ $g['orders_delivered'] ?? 0 }}</td>
            <td class="num">{{ $money($g['revenue']) }}</td>
            <td class="num">{{ $money($g['advertising']) }}</td>
            <td class="num {{ $g['contribution'] < 0 ? 'bad' : '' }}">{{ $money($g['contribution']) }}</td>
            <td class="num">{{ $pct($g['margin_pct'] ?? null) }}</td>
            <td><div class="bar {{ $g['contribution'] < 0 ? 'neg' : '' }}" style="width: {{ max(1, round(abs($g['contribution']) / $maxContribution * 100)) }}%"></div></td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Sin ventas en el período.</td></tr>
    @endforelse
    @if (count($products) > 15)<tr><td colspan="7" class="muted">… y {{ count($products) - 15 }} más (el detalle completo está en el Excel).</td></tr>@endif
</table>

<h2>Por ciudad</h2>
<table class="grid">
    <tr><th>Ciudad</th><th class="num">Entregadas</th><th class="num">Ingresos</th><th class="num">Delivery</th><th class="num">Publicidad</th><th class="num">Margen de contribución</th><th class="num">%</th></tr>
    @forelse (array_filter($r['by_city'] ?? [], fn ($g) => ($g['revenue'] ?? 0) != 0 || ($g['contribution'] ?? 0) != 0) as $g)
        <tr>
            <td>{{ $g['name'] }}</td>
            <td class="num">{{ $g['orders_delivered'] ?? 0 }}</td>
            <td class="num">{{ $money($g['revenue']) }}</td>
            <td class="num">{{ $money($g['delivery']) }}</td>
            <td class="num">{{ $money($g['advertising']) }}</td>
            <td class="num {{ $g['contribution'] < 0 ? 'bad' : '' }}">{{ $money($g['contribution']) }}</td>
            <td class="num">{{ $pct($g['margin_pct'] ?? null) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Sin ventas en el período.</td></tr>
    @endforelse
</table>

<div class="avoid">
<h2>Liquidez</h2>
<p class="muted" style="margin: 0 0 4px;">Foto de la caja al generar el reporte. Los Bs se pasan a USD con la tasa de Binance{{ !empty($cash['binance_rate']) ? ' (Bs ' . number_format($cash['binance_rate'], 2, ',', '.') . ')' : '' }}: es aproximado.</p>
<table class="kpis">
    <tr>
        <td><div class="kpi"><div class="label">Caja disponible</div><div class="value">≈ {{ $money($cash['available_usd'] ?? null) }}</div></div></td>
        <td><div class="kpi"><div class="label">Dinero en agencias</div><div class="value">≈ {{ $money($cash['agencies_usd'] ?? null) }}</div></div></td>
        <td><div class="kpi"><div class="label">Por cobrar</div><div class="value">≈ {{ $money($cash['receivable_usd'] ?? null) }}</div></div></td>
        <td><div class="kpi"><div class="label">Por pagar</div><div class="value {{ ($cash['payable_overdue_usd'] ?? 0) > 0 ? 'bad' : '' }}">≈ {{ $money($cash['payable_usd'] ?? null) }}</div>
            <div class="muted">{{ ($cash['payable_overdue_usd'] ?? 0) > 0 ? $money($cash['payable_overdue_usd']) . ' vencido' : 'nada vencido' }}</div></div></td>
    </tr>
    <tr>
        <td><div class="kpi"><div class="label">Entradas del período</div><div class="value good">≈ {{ $money($cash['inflow_usd'] ?? null) }}</div></div></td>
        <td><div class="kpi"><div class="label">Salidas del período</div><div class="value">≈ {{ $money($cash['outflow_usd'] ?? null) }}</div></div></td>
        <td colspan="2"><div class="kpi"><div class="label">Disponible para comprar inventario (§110)</div>
            <div class="value">≈ {{ $money($cash['inventory_capital_usd'] ?? null) }}</div>
            <div class="muted">caja − por pagar − gastos recurrentes de los próximos {{ $cash['upcoming_days'] ?? 30 }} días</div></div></td>
    </tr>
</table>
</div>
@if (!empty($cash['upcoming']))
    <table class="grid avoid" style="margin-top: 4px;">
        <tr><th>Próximos pagos</th><th>A quién</th><th>Qué</th><th class="num">Monto</th></tr>
        @foreach (array_slice($cash['upcoming'], 0, 12) as $u)
            <tr><td>{{ $u['date'] ? $day($u['date']) : 'sin fecha' }}@if ($u['overdue']) <span class="bad">· vencido</span>@endif</td>
                <td>{{ $u['payee'] }}</td><td>{{ $u['description'] }}</td><td class="num">{{ $bs($u['amount'], $u['currency']) }}</td></tr>
        @endforeach
        @if (count($cash['upcoming']) > 12)<tr><td colspan="4" class="muted">… y {{ count($cash['upcoming']) - 12 }} más.</td></tr>@endif
    </table>
@endif

<div class="avoid">
<h2>CPA financiero por producto + ciudad (§69)</h2>
@if (empty($r['cpa']))
    <p class="muted">Todavía no hay un CPA financiero calculado.</p>
@else
    <p class="muted" style="margin: 0 0 4px;">El último cálculo, con la ventana madura del {{ $day($r['cpa'][0]['period_from'] ?? null) }} al {{ $day($r['cpa'][0]['period_to'] ?? null) }}.</p>
    <table class="grid">
        <tr><th>Producto / ciudad</th><th class="num">Compras Meta</th><th class="num">CPA Meta</th><th class="num">Break-even</th><th class="num">Target</th><th class="num">Fully Loaded</th><th>Estado</th></tr>
        @foreach ($r['cpa'] as $v)
            <tr>
                <td>{{ $short($v['name'], 60) }}</td>
                <td class="num">{{ number_format($v['purchases'], 0, ',', '.') }}</td>
                <td class="num {{ $v['meta_cpa'] !== null && $v['target'] !== null && $v['meta_cpa'] > $v['target'] ? 'bad' : '' }}">{{ $money($v['meta_cpa']) }}</td>
                <td class="num">{{ $money($v['break_even']) }}</td>
                <td class="num"><b>{{ $money($v['target']) }}</b></td>
                <td class="num muted">{{ $money($v['fully_loaded']) }}</td>
                <td>{{ $v['purchases'] <= 0 ? 'sin compras de Meta' : ($v['viable'] ? 'viable' : 'NO VIABLE') }}</td>
            </tr>
        @endforeach
    </table>
    <p class="muted" style="margin: 3px 0 0;">Fully Loaded CPA es una métrica secundaria: incluye gastos fijos repartidos y no debe usarse sola para apagar campañas (§74).</p>
@endif
</div>

<table class="two" style="margin-top: 6px;"><tr>
    <td class="col">
        <h2>Cohortes (§59)</h2>
        <p style="margin: 0;">Creados en el período: <b>{{ $r['cohorts']['total']['created'] ?? 0 }}</b> · entregados <b>{{ $r['cohorts']['total']['delivered'] ?? 0 }}</b>
            · efectividad COD <b>{{ $pct($r['cohorts']['total']['effectiveness'] ?? null) }}</b></p>
        @if (!empty($r['cohorts']['effectiveness']))
            <table class="grid" style="margin-top: 4px;">
                <tr><th>Producto / ciudad</th><th class="num">Creados</th><th class="num">Efectividad</th></tr>
                @foreach (array_slice($r['cohorts']['effectiveness'], 0, 8) as $e)
                    <tr><td>{{ $short($e['name'], 48) }}</td><td class="num">{{ $e['created'] }}</td><td class="num">{{ $pct($e['effectiveness']) }}</td></tr>
                @endforeach
            </table>
        @endif
    </td>
    <td class="col">
        <h2>Operación</h2>
        <p style="margin: 0 0 3px;">Transporte de inventario: <b>{{ $money($r['transport']['total_usd'] ?? 0) }}</b> en {{ $r['transport']['trips'] ?? 0 }} viaje(s), {{ $r['transport']['units'] ?? 0 }} unidades (§13).</p>
        <p style="margin: 0 0 3px;">Delivery: <b>{{ $money($c['delivery'] ?? 0) }}</b>@if (($c['delivery_legacy'] ?? 0) > 0) (de los cuales {{ $money($c['delivery_legacy']) }} es el pago por orden de antes del 03/10)@endif.</p>
        <p style="margin: 6px 0 2px;"><b>Cierres de agencias pendientes:</b> {{ count($r['closures_pending'] ?? []) }}</p>
        @foreach (array_slice($r['closures_pending'] ?? [], 0, 8) as $cl)
            <div class="muted">· {{ $cl['agency'] }}: {{ $day($cl['from']) }} al {{ $day($cl['to']) }} ({{ str_replace('_', ' ', $cl['status']) }})</div>
        @endforeach
    </td>
</tr></table>

@if (!empty($r['status']['reasons']) || !empty($r['warnings']))
    <div class="avoid">
    <h2>Estado y avisos</h2>
    @foreach ($r['status']['reasons'] ?? [] as $reason)
        <div class="note warn">{{ $reason }}</div>
    @endforeach
    @foreach ($r['warnings'] ?? [] as $w)
        <div class="note">{{ $w }}</div>
    @endforeach
    <p class="muted">Consolidado no significa congelado: una entrega tardía puede cambiar las cifras, y entonces sale otra versión de este reporte (§20, §21).</p>
    </div>
@endif
</body>
</html>
