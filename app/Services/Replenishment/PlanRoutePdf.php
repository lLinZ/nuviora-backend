<?php

namespace App\Services\Replenishment;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El PDF de ruta para el chofer (Módulo 5 de Fran, entrega 4; §112–§120). Sale del plan aprobado: las cantidades son
 * exactamente las aprobadas (caso 26). Solo logística: nada de costos, precios, CPA, márgenes ni capital (§113).
 * - Las paradas siguen la ruta: ida por sus almacenes en orden y vuelta (Valencia → Maracay → Caracas → Maracay → Valencia,
 *   §61). Cada traslado se retira en la primera visita a su origen y se entrega en la siguiente visita a su destino; las
 *   paradas sin nada que hacer se saltan (§61, §120).
 * - Por parada: el almacén con su link de Google Maps (§116), RETIRAR, ENTREGAR y DEJAR EN ESTE ALMACÉN para lo recién
 *   comprado que se queda ahí (§115). Lo que depende de una compra dice RETIRAR SOLO DESPUÉS DE RECEPCIÓN DEL PROVEEDOR
 *   (§118). Las tallas nunca se juntan (§119).
 * - Tabla resumen por producto, talla y origen, con una columna por almacén (§117).
 */
class PlanRoutePdf
{
    public function data(int $forecastId): array
    {
        $forecast = DB::table('inventory_forecasts')->where('id', $forecastId)->first();
        abort_if(!$forecast, 404);
        if (!$forecast->approved_at) {
            throw ValidationException::withMessages(['plan' => 'El PDF de ruta sale de un plan aprobado: apruébalo primero (§112).']);
        }
        $warehouses = DB::table('warehouses as w')->leftJoin('cities as c', 'c.id', '=', 'w.city_id')
            ->get(['w.id', 'w.name', 'w.maps_url', 'w.latitude', 'w.longitude', 'c.name as city'])->keyBy('id');
        $products = DB::table('products')->get(['id', 'title', 'showable_name'])->keyBy('id');
        $variants = DB::table('product_variants')->pluck('title', 'id');
        $name = fn ($p) => ($products[$p]->showable_name ?? null) ?: ($products[$p]->title ?? "#{$p}");

        $moves = DB::table('forecast_transfer_recommendations as r')->leftJoin('inventory_transfers as t', 't.id', '=', 'r.inventory_transfer_id')
            ->where('r.forecast_id', $forecastId)->where('r.approved_qty', '>', 0)->orderBy('r.id')
            ->get(['r.product_id', 'r.variant_id', 'r.approved_qty', 'r.approved_from_warehouse_id', 'r.approved_to_warehouse_id', 't.purchase_id'])
            ->map(fn ($m) => ['product_id' => (int) $m->product_id, 'variant_id' => $m->variant_id ? (int) $m->variant_id : null, 'qty' => (int) $m->approved_qty,
                'from' => (int) $m->approved_from_warehouse_id, 'to' => (int) $m->approved_to_warehouse_id, 'depends' => $m->purchase_id !== null])->all();
        // Lo comprado que se queda donde llega (§115: DEJAR EN ESTE ALMACÉN)
        $keeps = [];
        foreach (DB::table('forecast_purchase_recommendations')->where('forecast_id', $forecastId)->where('approved_qty', '>', 0)->get() as $p) {
            $out = array_sum(array_map(fn ($m) => $m['depends'] && $m['from'] === (int) $p->approved_receiving_warehouse_id && $m['product_id'] === (int) $p->product_id
                && $m['variant_id'] === ($p->variant_id ? (int) $p->variant_id : null) ? $m['qty'] : 0, $moves));
            $keep = (int) $p->approved_qty - $out;
            if ($keep > 0) {
                $keeps[] = ['product_id' => (int) $p->product_id, 'variant_id' => $p->variant_id ? (int) $p->variant_id : null, 'qty' => $keep,
                    'warehouse' => (int) $p->approved_receiving_warehouse_id];
            }
        }

        // La ruta: la activa que más almacenes del plan toca; ida y vuelta (§61)
        $involved = array_unique(array_merge(array_column($moves, 'from'), array_column($moves, 'to'), array_column($keeps, 'warehouse')));
        $best = null;
        foreach (DB::table('replenishment_routes')->where('is_active', true)->orderBy('id')->get() as $r) {
            $stops = DB::table('replenishment_route_stops')->where('route_id', $r->id)->orderBy('position')->pluck('warehouse_id')->map(fn ($x) => (int) $x)->all();
            $hits = count(array_intersect($stops, $involved));
            if (!$best || $hits > $best['hits']) {
                $best = ['name' => $r->name, 'stops' => $stops, 'hits' => $hits];
            }
        }
        $stops = $best['stops'] ?? [];
        $path = array_merge($stops, array_reverse(array_slice($stops, 0, -1)));
        $visits = [];
        $others = [];
        foreach ($moves as $m) {
            $pick = array_search($m['from'], $path, true);
            $drop = null;
            if ($pick !== false) {
                foreach ($path as $i => $w) {
                    if ($i > $pick && $w === $m['to']) {
                        $drop = $i;
                        break;
                    }
                }
            }
            if ($pick === false || $drop === null) {
                $others[] = $m;
                continue;
            }
            $visits[$pick]['pickup'][] = $m;
            $visits[$drop]['deliver'][] = $m;
        }
        foreach ($keeps as $k) {
            $i = array_search($k['warehouse'], $path, true);
            if ($i !== false) {
                $visits[$i]['keep'][] = $k;
            } else {
                $others[] = $k + ['keep' => true];
            }
        }
        ksort($visits);

        $label = fn (array $m) => ['product' => $name($m['product_id']), 'variant' => $m['variant_id'] ? ($variants[$m['variant_id']] ?? null) : null, 'qty' => $m['qty']];
        $stopsOut = [];
        foreach ($visits as $i => $v) {
            $w = $warehouses[$path[$i]];
            $stopsOut[] = [
                'warehouse' => $w->name,
                'city' => $w->city,
                'maps' => $this->mapsUrl($w),
                'pickup' => array_map(fn ($m) => $label($m) + ['to' => $warehouses[$m['to']]->name], array_values(array_filter($v['pickup'] ?? [], fn ($m) => !$m['depends']))),
                'pickup_after_receipt' => array_map(fn ($m) => $label($m) + ['to' => $warehouses[$m['to']]->name], array_values(array_filter($v['pickup'] ?? [], fn ($m) => $m['depends']))),
                'deliver' => array_map(fn ($m) => $label($m) + ['from' => $warehouses[$m['from']]->name], $v['deliver'] ?? []),
                'keep' => array_map($label, $v['keep'] ?? []),
            ];
        }
        // §114: "Valencia — Maracay — Caracas — Valencia": el recorrido de la primera a la última parada, con lo que se pasa
        $routeLabel = [];
        $span = $visits ? range(array_key_first($visits), array_key_last($visits)) : [];
        foreach ($span as $i) {
            $place = $warehouses[$path[$i]]->city ?: $warehouses[$path[$i]]->name;
            if (end($routeLabel) !== $place) {
                $routeLabel[] = $place;
            }
        }

        // Tabla resumen (§117): por producto, talla y origen; una columna por almacén de la ruta
        $columns = array_values(array_unique(array_merge($stops, array_column($moves, 'to'), array_column($keeps, 'warehouse'))));
        $rows = [];
        foreach ($moves as $m) {
            $rk = "{$m['product_id']}|{$m['variant_id']}|{$m['from']}";
            $rows[$rk] ??= $label($m) + ['origin' => $warehouses[$m['from']]->name, 'cells' => [], 'total' => 0];
            $rows[$rk]['cells'][$m['to']] = ($rows[$rk]['cells'][$m['to']] ?? 0) + $m['qty'];
            $rows[$rk]['total'] += $m['qty'];
        }
        foreach ($keeps as $k) {
            $rk = "{$k['product_id']}|{$k['variant_id']}|{$k['warehouse']}";
            $rows[$rk] ??= $label($k) + ['origin' => $warehouses[$k['warehouse']]->name, 'cells' => [], 'total' => 0];
            $rows[$rk]['cells'][$k['warehouse']] = ($rows[$rk]['cells'][$k['warehouse']] ?? 0) + $k['qty'];
            $rows[$rk]['total'] += $k['qty'];
        }

        return [
            'plan_id' => $forecastId,
            'date' => date('d/m/Y'),
            'route' => $routeLabel ? implode(' — ', $routeLabel) : ($best['name'] ?? 'Sin ruta configurada'),
            'generated_at' => now()->format('d/m/Y H:i'),
            'stops' => $stopsOut,
            'others' => array_map(fn ($m) => $label($m) + [
                'from' => isset($m['from']) ? $warehouses[$m['from']]->name : null,
                'to' => isset($m['to']) ? $warehouses[$m['to']]->name : ($warehouses[$m['warehouse']]->name ?? null),
                'keep' => !empty($m['keep']), 'depends' => !empty($m['depends']),
            ], $others),
            'columns' => array_map(fn ($id) => ['id' => $id, 'name' => $warehouses[$id]->city ?: $warehouses[$id]->name], $columns),
            'summary' => array_values($rows),
        ];
    }

    public function html(int $forecastId): string
    {
        return view('replenishment.route-pdf', ['d' => $this->data($forecastId)])->render();
    }

    public function pdf(int $forecastId): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($forecastId), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function mapsUrl(object $w): ?string
    {
        if ($w->maps_url && preg_match('#^https?://#i', trim($w->maps_url))) {
            return trim($w->maps_url);
        }
        if ($w->latitude !== null && $w->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query=' . (float) $w->latitude . ',' . (float) $w->longitude;
        }

        return null;
    }
}
