<?php

namespace App\Services\Replenishment;

/**
 * El cálculo del plan de inventario (Módulo 5 de Fran, entrega 3). Es puro: recibe todos los datos ya armados (PlanData)
 * y devuelve el plan, sin leer ni escribir la base. Así cada caso del §129 se prueba con datos exactos.
 *
 * Orden interno (§54): demanda → stock e incoming → exceso y déficit → traslados → déficit nacional → compra → reparto de
 * la compra → paradas de la ruta. Todo por producto + variante + almacén (§7–§9).
 *
 * Demanda (§22–§35), por producto + variante + almacén:
 * - D7, D14 y D30 = unidades entregadas por día en cada ventana (§30). Los pedidos Sin Stock de la ciudad suman según su %
 *   (§24, §25) y se reparten entre los almacenes de la ciudad según lo que entrega cada uno. Se combinan tasas, no conteos
 *   (§31). Con poco histórico, las ventanas que no existen no cuentan como cero: se re-normalizan los pesos (§29).
 * - Tendencia por producto + ciudad (§32–§35): el ritmo reciente (D7 y, si hay, los pedidos de los últimos días todavía sin
 *   madurar × la efectividad madura) contra la ventana más larga. Si el cambio pasa el umbral de "estable", el forecast se
 *   mueve la mitad del camino hacia el ritmo reciente; todo el camino si Meta lo confirma (§36–§39, §41, §77). Decisión
 *   nuestra (confirmar con Fran).
 * - Campañas todas pausadas (§39) o producto Pausado (§13): el forecast se multiplica por el % de demanda de un producto
 *   pausado (no llega a cero: puede haber demanda orgánica).
 * - Producto nuevo (§26): sin histórico suficiente usa los pedidos de prueba × efectividad y las compras de Meta, con
 *   confianza baja.
 *
 * Forecast vs compra (§44–§46): el forecast es lo que se cree que se consumirá; la compra ideal llega a la cobertura
 * objetivo + seguridad; la recomendada se ajusta por rentabilidad: CPA sobre el Break-even = sin seguridad y sin tendencia
 * al alza (§43); CPA entre Target y Break-even = sin seguridad (§42); Liquidación, Descontinuado o producto muriendo
 * (tendencia a la baja con campañas pausadas o CPA negativo) = no comprar (§13, §75).
 *
 * Traslados antes que compras (§50–§54): cada almacén apunta a cubrir los mismos días (§51, §52), nunca se deja el origen
 * por debajo de su objetivo (§53), no se usa lo reservado (§123), no se manda a una ciudad donde el producto no se vende
 * (§122), primero dentro de la misma ciudad (§11) y luego dentro de la ruta (§62). Mínimo de traslado (§68–§71): una parada
 * se visita si entre lo que entra y lo que sale suma el mínimo; un traslado se mantiene solo si su origen y su destino se
 * visitan. La compra llega al almacén de recepción del proveedor (§20) y de ahí se reparte con traslados que dependen de
 * la recepción (§57–§59).
 */
class PlanEngine
{
    public const VERSION = 'm5-plan-2'; // 2: azul contra la cobertura pedida, sin seguridad (R8 aprobado)

    private array $in;
    private array $s;

    public function compute(array $in): array
    {
        $this->in = $in;
        $this->s = $s = $in['settings'];
        $coverage = (float) $in['coverage_days'];
        $stable = (float) $s['trend_stable_pct'] / 100;
        $pausedFactor = (float) $s['paused_demand_pct'] / 100;
        $excessMult = (float) $s['excess_multiplier'];
        $lag = max(1, (int) ($in['maturity_lag_days'] ?? 4));
        $horizon = date('Y-m-d', strtotime($in['today'] . ' +' . (int) ceil($coverage) . ' days'));
        $warehouses = $in['warehouses'];
        $products = $in['products'];

        // ---- 1. Las líneas: producto + variante + almacén con stock, entregas o Sin Stock en su ciudad ----
        $keys = [];
        foreach (array_merge(array_keys($in['stock']), array_keys($in['delivered'])) as $k) {
            $keys[$k] = true;
        }
        foreach (array_keys($in['sin_stock']) as $ck) {
            [$c, $p, $v] = explode('|', $ck);
            foreach ($this->cityWarehouses((int) $c) as $w) {
                $keys["{$w}|{$p}|{$v}"] = true;
            }
        }
        foreach (array_keys($in['recent_created']) as $ck) { // producto nuevo: pedidos de prueba sin entregas todavía
            [$p, $c] = explode('|', $ck);
            if (empty($products[(int) $p]['has_variants'])) {
                foreach ($this->cityWarehouses((int) $c) as $w) {
                    $keys["{$w}|{$p}|0"] = true;
                }
            }
        }
        $lines = [];
        foreach (array_keys($keys) as $k) {
            [$w, $p, $v] = array_map('intval', explode('|', $k));
            if (!isset($warehouses[$w], $products[$p])) {
                continue;
            }
            $prod = $products[$p];
            $city = $warehouses[$w]['city_id'];
            $lines[$k] = [
                'key' => $k, 'warehouse_id' => $w, 'product_id' => $p, 'variant_id' => $v ?: null, 'city_id' => $city,
                'allowed' => $city === null || ($prod['cities'][$city] ?? true),
                'stock' => ($in['stock'][$k] ?? []) + ['available' => 0, 'reserved' => 0, 'physical' => 0, 'review' => 0, 'in_transit' => 0, 'incoming' => []],
                'days' => $in['delivered'][$k] ?? [],
            ];
        }

        // ---- 2. Demanda base por línea (§22–§31) ----
        $weights = $s['window_weights'];
        $windowWeight = [7 => (float) $weights['d7'], 14 => (float) $weights['d14'], 30 => (float) $weights['d30']];
        foreach ($lines as $k => &$l) {
            $hist = (int) ($products[$l['product_id']]['history_days'] ?? 0);
            $l['windows'] = [];
            foreach ([7, 14, 30] as $win) {
                if ($win === 7 || $hist >= $win) {
                    $span = $win === 7 ? max(1, min(7, $hist ?: 7)) : $win;
                    $l['windows'][$win] = ['span' => $span, 'delivered' => $this->sumDays($l['days'], $span), 'sin_stock' => 0.0];
                }
            }
            $l['delivered30'] = $this->sumDays($l['days'], 30);
        }
        unset($l);
        // Sin Stock: por ciudad, repartido entre sus almacenes según lo que entrega cada uno (o en partes iguales)
        foreach ($in['sin_stock'] as $ck => $days) {
            [$c, $p, $v] = array_map('intval', explode('|', $ck));
            $pct = (float) ($products[$p]['sin_stock_pct'] ?? $s['sin_stock_pct']) / 100;
            $targets = array_filter($lines, fn ($l) => $l['city_id'] === $c && $l['product_id'] === $p && (int) $l['variant_id'] === $v && $l['allowed']);
            if (!$targets || $pct <= 0) {
                continue;
            }
            $total = array_sum(array_column($targets, 'delivered30'));
            foreach ($targets as $tk => $t) {
                $share = $total > 0 ? $t['delivered30'] / $total : 1 / count($targets);
                foreach ($lines[$tk]['windows'] as $win => $w) {
                    $lines[$tk]['windows'][$win]['sin_stock'] += $this->sumDays($days, $w['span']) * $pct * $share;
                }
            }
        }
        foreach ($lines as &$l) {
            $sumW = 0.0;
            $base = 0.0;
            foreach ($l['windows'] as $win => $w) {
                $rate = ($w['delivered'] + $w['sin_stock']) / $w['span'];
                $l['rates'][$win] = round($rate, 4);
                $base += $rate * $windowWeight[$win];
                $sumW += $windowWeight[$win];
            }
            $l['base'] = $l['allowed'] && $sumW > 0 ? $base / $sumW : 0.0;
        }
        unset($l);

        // ---- 3. Tendencia y Meta por producto + ciudad (§32–§43) ----
        $groups = [];
        foreach ($lines as $l) {
            $gk = "{$l['product_id']}|{$l['city_id']}";
            $groups[$gk] ??= ['d7' => 0.0, 'ref' => 0.0, 'refWindow' => 7, 'base' => 0.0, 'lines' => 0];
            $longest = max(array_keys($l['windows']));
            $groups[$gk]['d7'] += ($l['windows'][7]['delivered']) / $l['windows'][7]['span'];
            $groups[$gk]['ref'] += $l['windows'][$longest]['delivered'] / $l['windows'][$longest]['span'];
            $groups[$gk]['refWindow'] = max($groups[$gk]['refWindow'], $longest);
            $groups[$gk]['base'] += $l['base'];
            $groups[$gk]['lines']++;
        }
        foreach ($groups as $gk => &$g) {
            [$p, $c] = explode('|', $gk);
            $p = (int) $p;
            $eff = (float) ($products[$p]['effectiveness'] ?? $in['global_effectiveness'] ?? 0.5);
            $recent = isset($in['recent_created'][$gk]) ? $in['recent_created'][$gk] / $lag * $eff : null;
            $g['recent_orders'] = $recent;
            $g['recent'] = $recent !== null ? ($g['d7'] + $recent) / 2 : $g['d7'];
            $hist = (int) ($products[$p]['history_days'] ?? 0);
            $g['trend'] = $hist < 7 || $g['refWindow'] === 7 ? null : ($g['ref'] > 0 ? $g['recent'] / $g['ref'] - 1 : ($g['recent'] > 0 ? 1.0 : 0.0));
            $meta = $in['meta'][$gk] ?? null;
            $g['meta'] = $meta;
            $g['spend_change'] = $meta && ($meta['spend_prev'] ?? 0) > 0 ? $meta['spend_recent'] / $meta['spend_prev'] - 1 : null;
            $g['all_paused'] = $meta && ($meta['campaigns'] ?? 0) > 0 && ($meta['active'] ?? 0) === 0;
            $g['cpa'] = $this->cpaHealth($in['cpa'][$gk] ?? null);
            $up = $g['trend'] !== null && $g['trend'] > $stable;
            $down = $g['trend'] !== null && $g['trend'] < -$stable;
            $g['meta_confirms'] = ($up && $g['spend_change'] !== null && $g['spend_change'] > $stable && $g['cpa']['health'] === 'healthy')
                || ($down && (($g['spend_change'] !== null && $g['spend_change'] < -$stable) || $g['all_paused']));
            $g['dying'] = $down && ($g['all_paused'] || $g['cpa']['health'] === 'negative');
        }
        unset($g);

        // ---- 4. Forecast, objetivo y cobertura por línea (§44–§49) ----
        $safetyGlobal = (float) $s['safety_days'];
        foreach ($lines as &$l) {
            $p = $l['product_id'];
            $prod = $products[$p];
            $g = $groups["{$p}|{$l['city_id']}"];
            $reasons = [];
            $f = $l['base'];
            $alpha = 0.0;
            if ($l['allowed'] && $g['trend'] !== null && abs($g['trend']) > $stable) {
                $alpha = $g['meta_confirms'] ? 1.0 : 0.5;
                $f = max(0.0, $l['base'] * (1 + $alpha * $g['trend']));
                $reasons[] = ($g['trend'] > 0 ? 'Demanda creciendo ' : 'Demanda bajando ') . $this->pct($g['trend'])
                    . ($g['meta_confirms'] ? ' y Meta lo confirma' : ': el forecast se mueve la mitad');
            }
            $limited = (int) ($prod['history_days'] ?? 0) < 14 || $l['delivered30'] < 5;
            if ($l['allowed'] && ((int) ($prod['history_days'] ?? 0) < 7 || $l['base'] == 0.0) && ($g['recent_orders'] || ($g['meta']['purchases_recent'] ?? 0) > 0)) {
                // §26: producto nuevo o sin entregas todavía: pedidos de prueba × efectividad, o compras de Meta
                $share = $g['lines'] > 0 ? 1 / $g['lines'] : 1;
                $eff = (float) ($prod['effectiveness'] ?? $in['global_effectiveness'] ?? 0.5);
                $signal = max((float) $g['recent_orders'], ((float) ($g['meta']['purchases_recent'] ?? 0)) / 7 * $eff) * $share;
                if ($signal > $f) {
                    $f = $signal;
                    $reasons[] = 'Forecast con histórico limitado: sale de los pedidos de prueba y de Meta';
                }
                $limited = true;
            }
            $paused = $prod['status'] === 'paused';
            if ($l['allowed'] && ($paused || $g['all_paused'])) {
                $f *= $pausedFactor;
                $reasons[] = ($paused ? 'Producto pausado' : 'Todas las campañas pausadas') . ': demanda al ' . round($pausedFactor * 100) . ' %';
            }
            $target = (float) ($prod['coverage_days'] ?? $coverage) + (float) ($prod['safety_days'] ?? $safetyGlobal);
            $incomingUseful = 0;
            $incomingLater = 0;
            foreach ($l['stock']['incoming'] as $inc) {
                if ($inc['expected_at'] === null || $inc['expected_at'] <= $horizon) {
                    $incomingUseful += (int) $inc['qty'];
                } else {
                    $incomingLater += (int) $inc['qty'];
                }
            }
            $available = (int) $l['stock']['available'];
            $proj = $available + (int) $l['stock']['in_transit'] + $incomingUseful;
            $l += [
                'forecast' => $f, 'alpha' => $alpha, 'reasons' => $reasons, 'limited' => $limited, 'target_days' => $target,
                'goal' => $f * $target, 'incoming_useful' => $incomingUseful, 'incoming_later' => $incomingLater, 'projected' => $proj,
                'coverage' => $f > 0 ? $proj / $f : null, 'days_left' => $f > 0 ? $available / $f : null,
            ];
        }
        unset($l);

        // ---- 5. Traslados con el stock que ya hay (§50–§54, §11, §122, §123) ----
        $transfers = [];
        $bySku = [];
        foreach ($lines as $k => $l) {
            $bySku["{$l['product_id']}|" . (int) $l['variant_id']][] = $k;
        }
        foreach ($bySku as $skuKey => $keysOfSku) {
            $surplus = [];
            $deficit = [];
            foreach ($keysOfSku as $k) {
                $l = $lines[$k];
                $goal = $l['allowed'] ? $l['goal'] : 0.0;
                $surplus[$k] = max(0, (int) floor(min($l['stock']['available'], $l['projected'] - $goal)));
                $deficit[$k] = $l['allowed'] ? max(0, (int) round($goal - $l['projected'])) : 0;
            }
            $order = array_keys(array_filter($deficit));
            usort($order, fn ($a, $b) => ($lines[$a]['coverage'] ?? 0) <=> ($lines[$b]['coverage'] ?? 0));
            foreach ($order as $dk) {
                $to = $lines[$dk];
                $sources = array_keys(array_filter($surplus, fn ($q, $sk) => $q > 0 && $sk !== $dk && $this->connected($lines[$sk]['warehouse_id'], $to['warehouse_id']),
                    ARRAY_FILTER_USE_BOTH));
                usort($sources, function ($a, $b) use ($lines, $surplus, $to) {
                    $sameA = $lines[$a]['city_id'] === $to['city_id'] ? 0 : 1;
                    $sameB = $lines[$b]['city_id'] === $to['city_id'] ? 0 : 1;
                    return [$sameA, -$surplus[$a]] <=> [$sameB, -$surplus[$b]];
                });
                foreach ($sources as $sk) {
                    if ($deficit[$dk] <= 0) {
                        break;
                    }
                    $q = min($deficit[$dk], $surplus[$sk]);
                    $transfers[] = ['from' => $lines[$sk]['warehouse_id'], 'to' => $to['warehouse_id'], 'product_id' => $to['product_id'],
                        'variant_id' => $to['variant_id'], 'quantity' => $q, 'depends_on_purchase' => false, 'kept' => true,
                        'from_key' => $sk, 'to_key' => $dk,
                        'reason' => $lines[$sk]['city_id'] === $to['city_id'] ? 'Exceso en la misma ciudad (§11)' : 'Exceso en otro almacén de la ruta (§50)'];
                    $surplus[$sk] -= $q;
                    $deficit[$dk] -= $q;
                }
            }
            foreach ($keysOfSku as $k) {
                $lines[$k]['surplus_left'] = $surplus[$k];
                $lines[$k]['deficit_after_transfers'] = $deficit[$k];
            }
        }

        // ---- 6. Compra: ideal y recomendada por línea, y a dónde llega (§45, §46, §56–§59, §75, §85–§88) ----
        $purchases = [];
        foreach ($bySku as $skuKey => $keysOfSku) {
            [$p, $v] = array_map('intval', explode('|', $skuKey));
            $prod = $products[$p];
            $ideal = 0;
            $recommended = 0;
            $perLine = [];
            $notes = [];
            foreach ($keysOfSku as $k) {
                $l = $lines[$k];
                $need = $l['deficit_after_transfers'];
                if ($need <= 0) {
                    continue;
                }
                $g = $groups["{$p}|{$l['city_id']}"];
                $rec = $need;
                if (in_array($prod['status'], ['liquidation', 'discontinued'], true)) {
                    $rec = 0;
                    $notes['status'] = $prod['status'] === 'liquidation' ? 'Producto en liquidación: no se compra (§13)' : 'Producto descontinuado: no se compra (§13)';
                } elseif ($g['dying']) {
                    $rec = 0;
                    $notes['dying'] = 'Demanda cayendo con campañas pausadas o CPA negativo: no reponer, evaluar liquidación (§75)';
                } elseif ($g['cpa']['health'] === 'negative') {
                    $conservative = min($l['forecast'], $l['base']) * (float) ($prod['coverage_days'] ?? $coverage);
                    $rec = max(0, min($need, (int) round($conservative - $l['projected'])));
                    $notes['negative'] = 'CPA sobre el Break-even: compra prudente, sin días de seguridad ni tendencia al alza (§43)';
                } elseif ($g['cpa']['health'] === 'warning') {
                    $noSafety = $l['forecast'] * (float) ($prod['coverage_days'] ?? $coverage);
                    $rec = max(0, min($need, (int) round($noSafety - $l['projected'])));
                    $notes['warning'] = 'CPA cerca del Break-even: compra sin días de seguridad (§42)';
                }
                $ideal += $need;
                $recommended += $rec;
                $perLine[$k] = ['need' => $need, 'rec' => $rec];
            }
            if ($ideal <= 0) {
                continue;
            }
            $supplier = $prod['supplier'] ?? null;
            $receiving = $supplier['receiving_warehouse_id'] ?? null;
            if ($receiving !== null && !isset($warehouses[$receiving])) {
                $receiving = null;
            }
            // El reparto: lo de cada almacén; si llega a otro, un traslado que depende de la recepción (§58, §59)
            $receivingKey = $receiving !== null ? "{$receiving}|{$p}|{$v}" : null;
            foreach ($perLine as $k => $pl) {
                if ($pl['rec'] <= 0) {
                    continue;
                }
                $w = $lines[$k]['warehouse_id'];
                if ($receiving !== null && $receiving !== $w) {
                    $transfers[] = ['from' => $receiving, 'to' => $w, 'product_id' => $p, 'variant_id' => $v ?: null, 'quantity' => $pl['rec'],
                        'depends_on_purchase' => true, 'kept' => $this->connected($receiving, $w), 'from_key' => $receivingKey, 'to_key' => $k,
                        'reason' => 'Mercancía recién comprada: retirar solo después de la recepción del proveedor (§58)',
                        'unreachable' => !$this->connected($receiving, $w)];
                }
            }
            $purchases[$skuKey] = ['product_id' => $p, 'variant_id' => $v ?: null, 'ideal' => $ideal, 'recommended' => $recommended,
                'per_line' => $perLine, 'notes' => array_values($notes), 'supplier' => $supplier, 'receiving_warehouse_id' => $receiving];
        }

        // ---- 7. Mínimo de traslado y paradas de la ruta (§68–§71) ----
        $min = (int) $s['transfer_min_units'];
        do {
            $touch = [];
            foreach ($transfers as $t) {
                if ($t['kept']) {
                    $touch[$t['from']] = ($touch[$t['from']] ?? 0) + $t['quantity'];
                    $touch[$t['to']] = ($touch[$t['to']] ?? 0) + $t['quantity'];
                }
            }
            $changed = false;
            foreach ($transfers as &$t) {
                if ($t['kept'] && (($touch[$t['from']] ?? 0) < $min || ($touch[$t['to']] ?? 0) < $min)) {
                    $t['kept'] = false;
                    $t['dropped_small'] = true;
                    $changed = true;
                }
            }
            unset($t);
        } while ($changed);
        // Un traslado de mercancía comprada que no se hace: esa parte no se compra todavía (§71)
        foreach ($transfers as $t) {
            if ($t['depends_on_purchase'] && !$t['kept']) {
                $skuKey = "{$t['product_id']}|" . (int) $t['variant_id'];
                $purchases[$skuKey]['recommended'] -= $t['quantity'];
                $purchases[$skuKey]['per_line'][$t['to_key']]['rec'] -= $t['quantity'];
                $purchases[$skuKey]['per_line'][$t['to_key']]['dropped'] = $t['quantity'];
            }
        }

        // ---- 8. Lead time, semáforo, score y balance final por línea (§48, §49, §73, §74, §78–§83, §121) ----
        $maxForecast = max(array_merge([0.0], array_column($lines, 'forecast')));
        $deadDays = (int) $s['dead_stock_days'];
        foreach ($lines as $k => &$l) {
            $p = $l['product_id'];
            $prod = $products[$p];
            $g = $groups["{$p}|{$l['city_id']}"];
            $supplier = $prod['supplier'] ?? null;
            $receiving = $supplier['receiving_warehouse_id'] ?? null;
            $lead = $supplier !== null ? (float) $supplier['lead_time_days'] + ($receiving !== null && $receiving !== $l['warehouse_id']
                ? $this->routeLead($receiving, $l['warehouse_id']) : 0.0) : null;
            $inQty = 0;
            $outQty = 0;
            foreach ($transfers as $t) {
                if ($t['kept'] && $t['to_key'] === $k) {
                    $inQty += $t['quantity'];
                }
                if ($t['kept'] && $t['from_key'] === $k) {
                    $outQty += $t['quantity'];
                }
            }
            $skuKey = "{$p}|" . (int) $l['variant_id'];
            $ownPurchase = 0;
            if (isset($purchases[$skuKey])) {
                $pr = $purchases[$skuKey];
                $lineRec = max(0, $pr['per_line'][$k]['rec'] ?? 0);
                if ($pr['receiving_warehouse_id'] === null || $pr['receiving_warehouse_id'] === $l['warehouse_id']) {
                    $ownPurchase = $lineRec; // sin almacén de recepción, lo comprado se cuenta donde hace falta
                }
                if ($pr['receiving_warehouse_id'] === $l['warehouse_id']) {
                    $ownPurchase += array_sum(array_map(fn ($t) => $t['kept'] && $t['depends_on_purchase'] && $t['from'] === $l['warehouse_id']
                        && $t['product_id'] === $p && (int) $t['variant_id'] === (int) $l['variant_id'] ? $t['quantity'] : 0, $transfers));
                }
            }
            $after = $l['projected'] + $inQty - $outQty + $ownPurchase;
            $l['transfers_in'] = $inQty;
            $l['transfers_out'] = $outQty;
            $l['purchase_here'] = $ownPurchase;
            $l['after'] = $after;
            $l['coverage_after'] = $l['forecast'] > 0 ? $after / $l['forecast'] : null;
            $l['lead_days'] = $lead;
            $l['stockout_date'] = $l['days_left'] !== null ? date('Y-m-d', strtotime($this->in['today'] . ' +' . (int) floor($l['days_left']) . ' days')) : null;
            $leadEff = $lead ?? 1.0;
            // Demanda despreciable: en todo el horizonte no llega a media unidad (decisión nuestra: no es urgente ni se compra)
            $relevant = $l['allowed'] && $l['forecast'] * $l['target_days'] >= 0.5;
            $l['buy_today'] = $relevant && $l['days_left'] !== null && $l['days_left'] <= $leadEff + (float) ($prod['safety_days'] ?? $safetyGlobal);
            $daysSince = $this->in['days_since_delivery'][$k] ?? null;
            $l['dead'] = $l['stock']['physical'] > 0 && ($daysSince === null || $daysSince >= $deadDays);
            $l['cpa'] = $g['cpa'];
            $l['trend'] = $g['trend'];
            $l['meta'] = ['all_paused' => $g['all_paused'], 'spend_change' => $g['spend_change'], 'confirms' => $g['meta_confirms']];
            $l['confidence'] = $l['limited'] ? 'low' : (($prod['history_days'] ?? 0) < 30 || $l['delivered30'] < 20 ? 'medium' : 'high');
            // Semáforo (§78). R8, aprobado por Fran: azul = "más del doble de lo que pediste: pides 7 días y una agencia
            // tiene para 15", así que el exceso se mide contra la cobertura pedida, sin los días de seguridad.
            $requested = (float) ($prod['coverage_days'] ?? $coverage);
            if (in_array($prod['status'], ['liquidation', 'discontinued'], true) || $g['dying']) {
                $l['light'] = 'black';
            } elseif (!$relevant) {
                $l['light'] = $l['stock']['physical'] > 0 ? 'black' : 'green';
            } elseif ($l['stock']['available'] <= 0 || $l['days_left'] <= $leadEff) {
                $l['light'] = 'red';
            } elseif ($l['coverage'] < $l['target_days']) {
                $l['light'] = 'orange';
            } elseif ($l['coverage'] > $requested * $excessMult) {
                $l['light'] = 'blue';
            } else {
                $l['light'] = 'green';
            }
            $l['excess_units'] = $l['forecast'] > 0 ? max(0, (int) floor($l['projected'] - $requested * $excessMult * $l['forecast'])) : 0;
            // Score de prioridad (§80–§83): ordena, no decide cantidades
            $w = $this->s['score_weights'];
            $comp = [
                'stockout' => $l['forecast'] <= 0 ? 0.0 : ($l['days_left'] !== null && $l['days_left'] <= $leadEff ? 1.0
                    : max(0.0, min(1.0, 1 - ($l['coverage'] ?? 0) / max(0.1, $l['target_days'])))),
                'velocity' => 0.5 * ($maxForecast > 0 ? $l['forecast'] / $maxForecast : 0) + 0.5 * max(0.0, min(1.0, 0.5 + ($g['trend'] ?? 0))),
                'lead_time' => $lead === null ? 0.5 : max(0.0, min(1.0, $lead / max(0.1, $l['target_days']))),
                'economics' => ['healthy' => 1.0, 'unknown' => 0.5, 'warning' => 0.5, 'negative' => 0.0][$g['cpa']['health']],
                'confidence' => ['high' => 1.0, 'medium' => 0.6, 'low' => 0.3][$l['confidence']],
            ];
            $l['score'] = $relevant ? (int) round(array_sum(array_map(fn ($c, $key) => $c * (float) $w[$key], $comp, array_keys($comp)))) : 0;
            $l['score_parts'] = $comp;
        }
        unset($l);

        // ---- 9. Bloques del resultado (§55, §79, §84–§88, §108–§111, §121) ----
        $purchaseRows = [];
        $capital = 0.0;
        $missingCost = 0;
        foreach ($purchases as $skuKey => $pr) {
            $p = $pr['product_id'];
            $prod = $products[$p];
            $prLines = array_map(fn ($k) => $lines[$k], array_keys($pr['per_line']));
            $score = max(array_merge([0], array_column($prLines, 'score')));
            $cost = $prod['unit_cost'] ?? null;
            $rec = max(0, $pr['recommended']);
            if ($cost === null && $rec > 0) {
                $missingCost += $rec;
            }
            $capital += $rec * (float) $cost;
            $first = $prLines[0];
            $agg = ['available' => 0, 'projected' => 0, 'forecast' => 0.0];
            foreach ($prLines as $pl) {
                $agg['available'] += $pl['stock']['available'];
                $agg['projected'] += $pl['projected'];
                $agg['forecast'] += $pl['forecast'];
            }
            $why = [
                "Stock proyectado disponible: {$agg['projected']}.",
                'Demanda prevista: ' . $this->num($agg['forecast']) . ' unidades/día.',
                'Cobertura actual: ' . ($agg['forecast'] > 0 ? $this->num($agg['projected'] / $agg['forecast']) : '—') . ' días.',
                'Cobertura objetivo + seguridad: ' . $this->num($first['target_days']) . ' días.',
            ];
            if ($first['trend'] !== null) {
                $why[] = 'Tendencia: ' . $this->pct($first['trend']) . '.';
            }
            $why[] = "Se requieren {$pr['ideal']} unidades para llegar al objetivo en los almacenes que les falta.";
            $reasonText = $pr['notes'] ? implode(' · ', $pr['notes']) : null;
            if (array_filter(array_column($pr['per_line'], 'dropped'))) {
                $reasonText = trim(($reasonText ? $reasonText . ' · ' : '') . 'Una parte no se compra todavía: su traslado sería muy pequeño y aislado (§71)');
            }
            $purchaseRows[] = [
                'product_id' => $p, 'variant_id' => $pr['variant_id'], 'product' => $prod['name'],
                'variant' => $pr['variant_id'] ? ($this->in['variants'][$pr['variant_id']]['title'] ?? null) : null,
                'ideal' => $pr['ideal'], 'recommended' => $rec, 'reason' => $reasonText,
                'unit_cost_usd' => $cost, 'cost_usd' => $cost !== null ? round($rec * $cost, 2) : null,
                'supplier_id' => $pr['supplier']['id'] ?? null, 'supplier' => $pr['supplier']['name'] ?? null,
                'receiving_warehouse_id' => $pr['receiving_warehouse_id'],
                'receiving_warehouse' => $pr['receiving_warehouse_id'] ? $warehouses[$pr['receiving_warehouse_id']]['name'] : null,
                'lead_time_days' => $pr['supplier']['lead_time_days'] ?? null,
                'score' => $score, 'priority' => $this->priority($score), 'buy_today' => (bool) array_filter(array_column($prLines, 'buy_today')),
                'explanation' => $why,
            ];
        }
        usort($purchaseRows, fn ($a, $b) => [$b['score'], $a['product']] <=> [$a['score'], $b['product']]);

        $transferRows = [];
        foreach ($transfers as $t) {
            if (!$t['kept']) {
                continue;
            }
            $transferRows[] = [
                'from_warehouse_id' => $t['from'], 'from' => $warehouses[$t['from']]['name'], 'to_warehouse_id' => $t['to'], 'to' => $warehouses[$t['to']]['name'],
                'product_id' => $t['product_id'], 'product' => $products[$t['product_id']]['name'], 'variant_id' => $t['variant_id'],
                'variant' => $t['variant_id'] ? ($this->in['variants'][$t['variant_id']]['title'] ?? null) : null,
                'quantity' => $t['quantity'], 'depends_on_purchase' => $t['depends_on_purchase'], 'reason' => $t['reason'],
            ];
        }

        $alerts = $this->alerts($lines, $transfers, $purchases, $groups);
        $cash = $in['cash_available'] ?? null;
        $balance = [];
        foreach ($lines as $l) {
            $w = $l['warehouse_id'];
            $balance[$w] ??= ['warehouse_id' => $w, 'warehouse' => $warehouses[$w]['name'], 'stock_now' => 0, 'stock_after' => 0, 'forecast' => 0.0];
            $balance[$w]['stock_now'] += $l['projected'];
            $balance[$w]['stock_after'] += $l['after'];
            $balance[$w]['forecast'] += $l['forecast'];
        }
        $balance = array_values(array_map(fn ($b) => $b + [
            'coverage_now' => $b['forecast'] > 0 ? round($b['stock_now'] / $b['forecast'], 1) : null,
            'coverage_after' => $b['forecast'] > 0 ? round($b['stock_after'] / $b['forecast'], 1) : null,
        ], $balance));
        usort($balance, fn ($a, $b) => strcmp($a['warehouse'], $b['warehouse']));

        $lineRows = [];
        foreach ($lines as $l) {
            $lineRows[] = [
                'warehouse_id' => $l['warehouse_id'], 'warehouse' => $warehouses[$l['warehouse_id']]['name'], 'city_id' => $l['city_id'],
                'product_id' => $l['product_id'], 'product' => $products[$l['product_id']]['name'], 'variant_id' => $l['variant_id'],
                'variant' => $l['variant_id'] ? ($this->in['variants'][$l['variant_id']]['title'] ?? null) : null,
                'allowed' => $l['allowed'], 'status' => $products[$l['product_id']]['status'],
                'physical' => (int) $l['stock']['physical'], 'reserved' => (int) $l['stock']['reserved'], 'available' => (int) $l['stock']['available'],
                'in_transit' => (int) $l['stock']['in_transit'], 'incoming' => $l['incoming_useful'], 'incoming_later' => $l['incoming_later'],
                'd7' => $l['rates'][7] ?? null, 'd14' => $l['rates'][14] ?? null, 'd30' => $l['rates'][30] ?? null,
                'base_daily' => round($l['base'], 3), 'forecast_daily' => round($l['forecast'], 3),
                'trend_pct' => $l['trend'] !== null ? round($l['trend'] * 100, 1) : null, 'trend' => $this->trendLabel($l['trend'], $stable),
                'target_days' => $l['target_days'], 'coverage_days' => $l['coverage'] !== null ? round($l['coverage'], 1) : null,
                'coverage_after' => $l['coverage_after'] !== null ? round($l['coverage_after'], 1) : null,
                'days_left' => $l['days_left'] !== null ? round($l['days_left'], 1) : null, 'stockout_date' => $l['stockout_date'],
                'lead_days' => $l['lead_days'], 'buy_today' => $l['buy_today'],
                'need_units' => $l['deficit_after_transfers'] ?? 0, 'excess_units' => $l['excess_units'],
                'transfers_in' => $l['transfers_in'], 'transfers_out' => $l['transfers_out'], 'purchase_here' => $l['purchase_here'], 'after' => $l['after'],
                'cpa' => $l['cpa'], 'meta' => $l['meta'], 'light' => $l['light'], 'score' => $l['score'], 'score_parts' => $l['score_parts'],
                'priority' => $this->priority($l['score']), 'confidence' => $l['confidence'], 'dead' => $l['dead'], 'reasons' => $l['reasons'],
            ];
        }
        usort($lineRows, fn ($a, $b) => [$b['score'], $a['product'], $a['warehouse']] <=> [$a['score'], $b['product'], $b['warehouse']]);

        return [
            'version' => self::VERSION,
            'today' => $in['today'],
            'coverage_days' => $coverage,
            'safety_days' => $safetyGlobal,
            'operative_days' => $coverage + $safetyGlobal,
            'purchases' => array_values(array_filter($purchaseRows, fn ($r) => $r['ideal'] > 0)),
            'transfers' => $transferRows,
            'alerts' => $alerts,
            'capital' => [
                'required_usd' => round($capital, 2),
                'cash_usd' => $cash,
                'additional_usd' => $cash !== null ? round(max(0, $capital - $cash), 2) : null,
                'units_without_cost' => $missingCost,
            ],
            'balance' => $balance,
            'lines' => $lineRows,
        ];
    }

    // -----------------------------------------------------------------------------------------------------------------

    private function alerts(array $lines, array $transfers, array $purchases, array $groups): array
    {
        $out = [];
        $wh = $this->in['warehouses'];
        $label = fn ($l) => $this->in['products'][$l['product_id']]['name'] . ($l['variant_id'] ? ' ' . ($this->in['variants'][$l['variant_id']]['title'] ?? '') : '')
            . ' · ' . $wh[$l['warehouse_id']]['name'];
        foreach ($lines as $l) {
            if ($l['light'] === 'red') {
                $out[] = ['level' => 'red', 'title' => $label($l), 'text' => ($l['stock']['available'] <= 0 ? 'Sin stock disponible.' : $this->num($l['days_left']) . ' días de cobertura: alcanza hasta el ' . $l['stockout_date'] . '.')
                    . ($l['lead_days'] !== null ? ' Reponer tarda ' . $this->num($l['lead_days']) . ' días.' : ' Falta configurar el proveedor.') . ($l['buy_today'] ? ' Comprar hoy.' : '')];
            } elseif ($l['light'] === 'blue') {
                $out[] = ['level' => 'blue', 'title' => $label($l), 'text' => "Exceso estimado: {$l['excess_units']} unidades (" . $this->num($l['coverage']) . ' días de cobertura).'];
            } elseif ($l['light'] === 'black' && $l['stock']['physical'] > 0) {
                $g = $groups["{$l['product_id']}|{$l['city_id']}"];
                $text = match (true) {
                    $this->in['products'][$l['product_id']]['status'] === 'liquidation' => 'En liquidación: priorizar la salida o redistribuirlo.',
                    $this->in['products'][$l['product_id']]['status'] === 'discontinued' => 'Descontinuado: no se repone.',
                    $g['dying'] => 'Demanda cayendo y campañas pausadas o CPA negativo. Recomendación: no reponer, evaluar liquidación.',
                    !$l['allowed'] => 'No se vende en esta ciudad: este stock se puede mover a otra.',
                    default => 'Sin demanda en este almacén.',
                };
                $out[] = ['level' => 'black', 'title' => $label($l), 'text' => $text];
            }
            if ($l['dead'] && $l['light'] !== 'black') {
                $out[] = ['level' => 'gray', 'title' => $label($l), 'text' => 'Inventario con baja o nula rotación: ' . $l['stock']['physical'] . ' unidades sin entregas en ' . (int) $this->s['dead_stock_days'] . ' días o más.'];
            }
        }
        foreach ($groups as $gk => $g) {
            if ($g['cpa']['health'] === 'negative') {
                [$p, $c] = explode('|', $gk);
                $city = collect($this->in['warehouses'])->firstWhere('city_id', (int) $c)['city'] ?? '';
                $out[] = ['level' => 'red', 'title' => $this->in['products'][(int) $p]['name'] . ($city ? " · {$city}" : ''),
                    'text' => 'Rentabilidad publicitaria negativa — Evitar sobrestock (CPA ' . $this->money($g['cpa']['meta_cpa']) . ' sobre el Break-even ' . $this->money($g['cpa']['break_even']) . ').'];
            }
        }
        foreach ($purchases as $pr) {
            if ($pr['recommended'] > 0 && empty($pr['supplier'])) {
                $out[] = ['level' => 'orange', 'title' => $this->in['products'][$pr['product_id']]['name'],
                    'text' => 'Falta configurar su proveedor y el almacén donde llega (Reabastecimiento › Proveedores): la compra se cuenta en cada almacén que la necesita.'];
            }
        }
        foreach ($transfers as $t) {
            if (!$t['kept'] && !empty($t['dropped_small']) && !$t['depends_on_purchase']) {
                $out[] = ['level' => 'gray', 'title' => $this->in['products'][$t['product_id']]['name'] . ' · ' . $wh[$t['to']]['name'],
                    'text' => "Faltan {$t['quantity']} unidades, pero el traslado desde " . $wh[$t['from']]['name'] . ' sería pequeño y aislado: queda como déficit para la próxima ruta (§71).'];
            }
            if (!empty($t['unreachable'])) {
                $out[] = ['level' => 'orange', 'title' => $this->in['products'][$t['product_id']]['name'] . ' · ' . $wh[$t['to']]['name'],
                    'text' => 'La compra llega a ' . $wh[$t['from']]['name'] . ' y no hay ruta hasta este almacén: configura una ruta.'];
            }
        }
        $order = ['red' => 0, 'orange' => 1, 'blue' => 2, 'black' => 3, 'gray' => 4];
        usort($out, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $out;
    }

    private function cpaHealth(?array $cpa): array
    {
        $meta = $cpa['meta_cpa'] ?? null;
        $target = $cpa['target'] ?? null;
        $be = $cpa['break_even'] ?? null;
        $health = match (true) {
            $meta === null || $be === null => 'unknown',
            $meta > $be => 'negative',
            $target !== null && $meta <= $target => 'healthy',
            default => 'warning',
        };

        return ['health' => $health, 'meta_cpa' => $meta, 'target' => $target, 'break_even' => $be];
    }

    private function connected(int $a, int $b): bool
    {
        $wa = $this->in['warehouses'][$a] ?? null;
        $wb = $this->in['warehouses'][$b] ?? null;
        if (!$wa || !$wb) {
            return false;
        }
        if ($wa['city_id'] !== null && $wa['city_id'] === $wb['city_id']) {
            return true;
        }
        foreach ($this->in['routes'] as $r) {
            if (in_array($a, $r['stops'], true) && in_array($b, $r['stops'], true)) {
                return true;
            }
        }

        return false;
    }

    private function routeLead(int $a, int $b): float
    {
        $best = null;
        foreach ($this->in['routes'] as $r) {
            if (in_array($a, $r['stops'], true) && in_array($b, $r['stops'], true)) {
                $best = $best === null ? (float) $r['lead_time_days'] : min($best, (float) $r['lead_time_days']);
            }
        }
        if ($best === null && ($this->in['warehouses'][$a]['city_id'] ?? null) === ($this->in['warehouses'][$b]['city_id'] ?? -1)) {
            return 0.0;
        }

        return $best ?? 1.0;
    }

    private function cityWarehouses(int $cityId): array
    {
        return array_keys(array_filter($this->in['warehouses'], fn ($w) => $w['city_id'] === $cityId));
    }

    /** Unidades de los días 1..$span (día 1 = ayer) */
    private function sumDays(array $days, int $span): float
    {
        $sum = 0.0;
        foreach ($days as $ago => $units) {
            if ($ago >= 1 && $ago <= $span) {
                $sum += $units;
            }
        }

        return $sum;
    }

    private function priority(int $score): string
    {
        $r = $this->s['score_ranges'];
        return match (true) {
            $score >= $r['critical'] => 'Crítico',
            $score >= $r['high'] => 'Alta prioridad',
            $score >= $r['monitor'] => 'Monitorizar',
            default => 'Baja prioridad',
        };
    }

    private function trendLabel(?float $trend, float $stable): string
    {
        return match (true) {
            $trend === null => 'Histórico limitado',
            $trend > $stable => 'Creciendo',
            $trend < -$stable => 'Disminuyendo',
            default => 'Estable',
        };
    }

    private function pct(float $v): string
    {
        return ($v >= 0 ? '+' : '−') . number_format(abs($v) * 100, 0, ',', '.') . ' %';
    }

    private function num(float $v): string
    {
        return number_format($v, abs($v) < 1 && $v != 0 ? 2 : 1, ',', '.');
    }

    private function money(?float $v): string
    {
        return $v === null ? '—' : '$' . number_format($v, 2, ',', '.');
    }
}
