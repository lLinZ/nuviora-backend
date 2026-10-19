@php
    // PDF de ruta para el chofer (Módulo 5 de Fran, §112–§120). Solo logística: nada de costos, precios ni dinero (§113).
    $item = fn ($l) => e($l['product']) . ($l['variant'] ? ' <b>' . e($l['variant']) . '</b>' : '') . ' — <b>' . (int) $l['qty'] . '</b>';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ruta de reposición · Plan #{{ $d['plan_id'] }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #444; margin-bottom: 14px; }
        .stop { border: 1.5px solid #222; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; page-break-inside: avoid; }
        .stop h2 { font-size: 14px; margin: 0 0 4px; }
        .maps a { color: #0b57d0; text-decoration: underline; }
        .label { font-weight: bold; margin-top: 6px; font-size: 11px; letter-spacing: .5px; }
        .warn { color: #b3261e; font-weight: bold; }
        ul { margin: 2px 0 0 16px; padding: 0; }
        li { margin: 1px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #999; padding: 3px 5px; text-align: center; }
        th { background: #eee; }
        td.l { text-align: left; }
    </style>
</head>
<body>
    <h1>RUTA DE REPOSICIÓN</h1>
    <div class="meta">
        Fecha: <b>{{ $d['date'] }}</b> · Ruta: <b>{{ $d['route'] }}</b> · Generado: {{ $d['generated_at'] }} · Plan #{{ $d['plan_id'] }}
    </div>

    @foreach ($d['stops'] as $i => $s)
        <div class="stop">
            <h2>{{ $i + 1 }}. ALMACÉN {{ mb_strtoupper($s['warehouse']) }}@if ($s['city']) <span style="font-weight:normal">({{ $s['city'] }})</span>@endif</h2>
            <div class="maps">
                @if ($s['maps'])<a href="{{ $s['maps'] }}">Abrir ubicación en Google Maps</a>@else <span class="warn">Este almacén no tiene ubicación cargada</span>@endif
            </div>
            @if ($s['pickup'])
                <div class="label">RETIRAR</div>
                <ul>@foreach ($s['pickup'] as $l)<li>{!! $item($l) !!} <span style="color:#555">→ {{ $l['to'] }}</span></li>@endforeach</ul>
            @endif
            @if ($s['pickup_after_receipt'])
                <div class="label warn">RETIRAR SOLO DESPUÉS DE RECEPCIÓN DEL PROVEEDOR</div>
                <ul>@foreach ($s['pickup_after_receipt'] as $l)<li>{!! $item($l) !!} <span style="color:#555">→ {{ $l['to'] }}</span></li>@endforeach</ul>
            @endif
            @if ($s['deliver'])
                <div class="label">ENTREGAR</div>
                <ul>@foreach ($s['deliver'] as $l)<li>{!! $item($l) !!} <span style="color:#555">(de {{ $l['from'] }})</span></li>@endforeach</ul>
            @endif
            @if ($s['keep'])
                <div class="label">DEJAR EN ESTE ALMACÉN (mercancía recién recibida)</div>
                <ul>@foreach ($s['keep'] as $l)<li>{!! $item($l) !!}</li>@endforeach</ul>
            @endif
        </div>
    @endforeach

    @if ($d['others'])
        <div class="stop">
            <h2>Otros movimientos (fuera de la ruta)</h2>
            <ul>
                @foreach ($d['others'] as $l)
                    <li>{!! $item($l) !!} —
                        @if ($l['keep']) dejar en {{ $l['to'] }}
                        @else {{ $l['from'] }} → {{ $l['to'] }}@if ($l['depends']) <span class="warn">(retirar solo después de recepción del proveedor)</span>@endif
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($d['summary'])
        <h2 style="font-size:14px;margin-top:14px">Resumen</h2>
        <table>
            <tr>
                <th class="l">Producto</th><th>Variante</th><th>Origen</th>
                @foreach ($d['columns'] as $c)<th>{{ $c['name'] }}</th>@endforeach
                <th>Total</th>
            </tr>
            @foreach ($d['summary'] as $r)
                <tr>
                    <td class="l">{{ $r['product'] }}</td><td>{{ $r['variant'] ?? '—' }}</td><td>{{ $r['origin'] }}</td>
                    @foreach ($d['columns'] as $c)<td>{{ $r['cells'][$c['id']] ?? 0 }}</td>@endforeach
                    <td><b>{{ $r['total'] }}</b></td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
