<?php

namespace App\Services\Replenishment;

use App\Models\InventoryAuditLog;
use App\Models\InventoryPurchase;
use App\Models\InventoryTransfer;
use App\Constants\OrderStatus;
use App\Services\Inventory\StockStatus;
use Illuminate\Support\Facades\DB;

/**
 * Seguimiento y autoevaluación del plan (Módulo 5 de Fran, entrega 4; §97–§105).
 * - Cuando termina el horizonte de un plan (el día en que se generó + su cobertura), se compara lo previsto con lo que de
 *   verdad se entregó en ese tiempo (§98): previsto = demanda diaria del plan × días del horizonte.
 * - Métricas (§99–§103): MAE, WAPE, Accuracy = 100 × (1 − WAPE) entre 0 y 100, Bias (positivo = sobreestima), tasa de
 *   quiebre (pedidos que pasaron a Sin Stock en el horizonte) y exceso (stock final por encima de la cobertura objetivo).
 *   Confiabilidad = pesos configurables de precisión, prevención de quiebre y control de exceso, mostrada con sus partes.
 * - Por nivel (§104): global, producto, variante, ciudad y almacén.
 * - Seguimiento (§97): aprobado, comprado, recibido y trasladado, junto al consumo real, el stock final, los quiebres y el
 *   exceso.
 * - No cambia nada de la configuración (§105): solo sugiere cuando un producto se sobreestima o subestima mucho.
 * - El stock final se toma del inventario al evaluar; si se evalúa más de 2 días después del horizonte, ya no es el del
 *   final y se deja sin medir.
 */
class PlanEvaluator
{
    /** Bias a partir del cual se sugiere revisar un producto (§101: "+15 %") */
    public const BIAS_SUGGESTION_PCT = 15;
    /** Unidades reales mínimas de un producto para sugerir algo (con menos, el % no dice nada) */
    public const SUGGESTION_MIN_UNITS = 5;

    public function __construct(private PlanData $data, private StockStatus $stock, private ReplenishmentConfig $config)
    {
    }

    /**
     * Las métricas de un grupo de líneas (§99–§103).
     *
     * @param array<int, array{predicted:float, actual:float, stockout:bool, excess:?int}> $rows
     */
    public function metrics(array $rows, ?array $weights = null): array
    {
        $weights ??= $this->config->settings()['reliability_weights'];
        $n = count($rows);
        $sumPred = 0.0;
        $sumActual = 0.0;
        $sumAbs = 0.0;
        $stockouts = 0;
        $measured = 0;
        $excessRows = 0;
        $excessUnits = 0;
        foreach ($rows as $r) {
            $sumPred += $r['predicted'];
            $sumActual += $r['actual'];
            $sumAbs += abs($r['predicted'] - $r['actual']);
            $stockouts += $r['stockout'] ? 1 : 0;
            if ($r['excess'] !== null) {
                $measured++;
                $excessRows += $r['excess'] > 0 ? 1 : 0;
                $excessUnits += max(0, (int) $r['excess']);
            }
        }
        if ($n === 0) {
            return ['lines' => 0, 'predicted' => 0, 'actual' => 0, 'abs_error' => 0, 'mae' => null, 'wape' => null, 'accuracy' => null, 'bias_pct' => null,
                'stockout_rate' => null, 'excess_rate' => null, 'excess_units' => 0, 'stockout_avoidance' => null, 'excess_control' => null, 'reliability' => null];
        }
        $wape = $sumActual > 0 ? $sumAbs / $sumActual : null;
        $accuracy = $wape !== null ? max(0.0, min(100.0, 100 * (1 - $wape))) : ($sumAbs == 0.0 ? 100.0 : 0.0);
        $stockoutRate = 100 * $stockouts / $n;
        $excessRate = $measured > 0 ? 100 * $excessRows / $measured : null;
        $avoidance = 100 - $stockoutRate;
        $control = $excessRate !== null ? 100 - $excessRate : null;
        // Sin stock final medido, la parte del exceso se reparte entre las otras dos
        $parts = ['accuracy' => $accuracy, 'stockout' => $avoidance, 'excess' => $control];
        $wsum = 0;
        $score = 0.0;
        foreach ($parts as $k => $v) {
            if ($v !== null) {
                $score += $v * (float) ($weights[$k] ?? 0);
                $wsum += (float) ($weights[$k] ?? 0);
            }
        }

        return [
            'lines' => $n,
            'predicted' => round($sumPred, 2),
            'actual' => round($sumActual, 2),
            'abs_error' => round($sumAbs, 2),
            'mae' => round($sumAbs / $n, 2),
            'wape' => $wape !== null ? round(100 * $wape, 1) : null,
            'accuracy' => round($accuracy, 1),
            'bias_pct' => $sumActual > 0 ? round(100 * ($sumPred - $sumActual) / $sumActual, 1) : null,
            'stockout_rate' => round($stockoutRate, 1),
            'excess_rate' => $excessRate !== null ? round($excessRate, 1) : null,
            'excess_units' => $excessUnits,
            'stockout_avoidance' => round($avoidance, 1),
            'excess_control' => $control !== null ? round($control, 1) : null,
            'reliability' => $wsum > 0 ? round($score / $wsum, 1) : null,
        ];
    }

    /** El último día del horizonte de un plan */
    public static function horizonEnd(object $forecast): string
    {
        $start = substr((string) $forecast->created_at, 0, 10);

        $days = max(1, (int) ceil((float) $forecast->coverage_days));

        return date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
    }

    /** Los planes cuyo horizonte ya terminó y todavía no se evaluaron */
    public function due(?string $today = null): array
    {
        $today ??= today()->toDateString();

        return DB::table('inventory_forecasts')->whereNull('evaluated_at')->orderBy('id')->get(['id', 'created_at', 'coverage_days'])
            ->filter(fn ($f) => self::horizonEnd($f) < $today)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Evalúa un plan cuyo horizonte terminó (§98). Guarda en cada línea lo real y en el plan las métricas. */
    public function evaluate(int $forecastId, ?string $today = null): array
    {
        $today ??= today()->toDateString();
        $forecast = DB::table('inventory_forecasts')->where('id', $forecastId)->first();
        abort_if(!$forecast, 404);
        $from = substr((string) $forecast->created_at, 0, 10);
        $to = self::horizonEnd($forecast);
        if ($to >= $today) {
            return ['evaluated' => false, 'reason' => "El horizonte de este plan termina el {$to}."];
        }
        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $stockKnown = (strtotime($today) - strtotime($to)) / 86400 <= 2;

        $actual = $this->data->deliveredBetween($from, $to);
        $stockouts = $this->stockoutsBetween($from, $to);
        $endStock = [];
        if ($stockKnown) {
            foreach ($this->stock->rows() as $r) {
                $endStock["{$r['warehouse_id']}|{$r['product_id']}|" . (int) $r['variant_id']] = (int) $r['available'];
            }
        }
        $cities = DB::table('cities')->pluck('name', 'id');
        $warehouses = DB::table('warehouses')->pluck('name', 'id');
        $products = DB::table('products')->get(['id', 'title', 'showable_name'])->keyBy('id');
        $variants = DB::table('product_variants')->pluck('title', 'id');
        $productName = fn ($p) => ($products[$p]->showable_name ?? null) ?: ($products[$p]->title ?? "#{$p}");

        $rows = [];
        $now = now();
        foreach (DB::table('inventory_forecast_lines')->where('forecast_id', $forecastId)->get() as $l) {
            $key = "{$l->warehouse_id}|{$l->product_id}|" . (int) $l->variant_id;
            $predicted = round((float) $l->forecast_daily * $days, 2);
            $real = round((float) ($actual[$key] ?? 0), 2);
            $stockout = $l->city_id !== null && (isset($stockouts["{$l->city_id}|{$l->product_id}|" . (int) $l->variant_id])
                || ($l->variant_id && isset($stockouts["{$l->city_id}|{$l->product_id}|0"])));
            $end = $stockKnown ? ($endStock[$key] ?? 0) : null;
            $excess = $end !== null ? max(0, (int) round($end - (float) $l->forecast_daily * (float) $l->target_days)) : null;
            DB::table('inventory_forecast_lines')->where('id', $l->id)->update([
                'predicted_units' => $predicted, 'actual_units' => $real, 'stockout' => $stockout, 'end_stock' => $end, 'excess_units' => $excess, 'evaluated_at' => $now,
            ]);
            // Lo que no se previó ni se vendió no dice nada de la precisión
            if ($predicted <= 0 && $real <= 0 && !$stockout) {
                continue;
            }
            $rows[] = ['predicted' => $predicted, 'actual' => $real, 'stockout' => $stockout, 'excess' => $excess,
                'product_id' => (int) $l->product_id, 'variant_id' => $l->variant_id ? (int) $l->variant_id : null,
                'warehouse_id' => (int) $l->warehouse_id, 'city_id' => $l->city_id !== null ? (int) $l->city_id : null];
        }

        $weights = $this->config->settings()['reliability_weights']; // §102: configurables
        $level = function (callable $key, callable $label) use ($rows, $weights) {
            $groups = [];
            foreach ($rows as $r) {
                $k = $key($r);
                if ($k !== null) {
                    $groups[$k][] = $r;
                }
            }
            $out = [];
            foreach ($groups as $k => $g) {
                $out[] = ['key' => (string) $k, 'label' => $label($g[0])] + $this->metrics($g, $weights);
            }
            usort($out, fn ($a, $b) => $b['actual'] <=> $a['actual'] ?: strcmp($a['label'], $b['label']));

            return $out;
        };
        $byProduct = $level(fn ($r) => $r['product_id'], fn ($r) => $productName($r['product_id']));
        $byVariant = $level(fn ($r) => $r['variant_id'] ? "{$r['product_id']}|{$r['variant_id']}" : null,
            fn ($r) => $productName($r['product_id']) . ' · ' . ($variants[$r['variant_id']] ?? "#{$r['variant_id']}"));
        $byCity = $level(fn ($r) => $r['city_id'], fn ($r) => $cities[$r['city_id']] ?? "#{$r['city_id']}");
        $byWarehouse = $level(fn ($r) => $r['warehouse_id'], fn ($r) => $warehouses[$r['warehouse_id']] ?? "#{$r['warehouse_id']}");

        // §105: solo sugerencias, explícitas; nada cambia solo
        $suggestions = [];
        foreach ($byProduct as $p) {
            if ($p['bias_pct'] !== null && $p['actual'] >= self::SUGGESTION_MIN_UNITS && abs($p['bias_pct']) > self::BIAS_SUGGESTION_PCT) {
                $suggestions[] = $p['bias_pct'] > 0
                    ? "Las previsiones de {$p['label']} están sobreestimando la demanda (+" . $this->pct($p['bias_pct']) . ' %).'
                    : "Las previsiones de {$p['label']} están subestimando la demanda (−" . $this->pct($p['bias_pct']) . ' %).';
            }
        }

        $evaluation = [
            'from' => $from, 'to' => $to, 'days' => $days, 'evaluated_on' => $today, 'stock_measured' => $stockKnown, 'weights' => $weights,
            'global' => $this->metrics($rows, $weights),
            'by_product' => $byProduct, 'by_variant' => $byVariant, 'by_city' => $byCity, 'by_warehouse' => $byWarehouse,
            'follow_up' => $this->followUp($forecastId, $rows, $stockKnown),
            'suggestions' => $suggestions,
        ];
        DB::table('inventory_forecasts')->where('id', $forecastId)->update(['evaluated_at' => $now, 'evaluation' => json_encode($evaluation), 'updated_at' => $now]);
        InventoryAuditLog::record('plan_evaluated', 'inventory_forecast', $forecastId, null, ['global' => $evaluation['global']]);

        return ['evaluated' => true] + $evaluation;
    }

    /** Evalúa todos los planes que ya terminaron su horizonte */
    public function evaluateDue(?string $today = null): array
    {
        $done = [];
        foreach ($this->due($today) as $id) {
            $r = $this->evaluate($id, $today);
            if ($r['evaluated']) {
                $done[] = $id;
            }
        }

        return $done;
    }

    /** La pestaña Precisión: los planes evaluados, el más nuevo primero */
    public function history(int $limit = 30): array
    {
        return DB::table('inventory_forecasts')->whereNotNull('evaluated_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'created_at', 'coverage_days', 'approved_at', 'evaluated_at', 'evaluation'])
            ->map(function ($f) {
                $e = json_decode((string) $f->evaluation, true) ?: [];

                return ['id' => (int) $f->id, 'generated_at' => substr((string) $f->created_at, 0, 16), 'coverage_days' => (float) $f->coverage_days,
                    'approved' => $f->approved_at !== null, 'evaluated_at' => substr((string) $f->evaluated_at, 0, 16)] + $e;
            })->all();
    }

    /**
     * Pedidos que pasaron a "Sin Stock" dentro del horizonte (§99: quiebres durante el horizonte), por ciudad, producto y
     * variante. Si alguno no tiene talla, cuenta para todas las tallas del producto en esa ciudad.
     */
    private function stockoutsBetween(string $from, string $to): array
    {
        $sinStockId = DB::table('statuses')->where('description', OrderStatus::SIN_STOCK)->value('id');
        if (!$sinStockId) {
            return [];
        }
        $out = [];
        $rows = DB::table('order_status_logs as l')->join('orders as o', 'o.id', '=', 'l.order_id')->join('order_products as op', 'op.order_id', '=', 'o.id')
            ->where('l.to_status_id', $sinStockId)->whereNotNull('o.city_id')
            ->whereBetween('l.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->distinct()->get(['o.city_id', 'op.product_id', 'op.variant_id']);
        foreach ($rows as $r) {
            $out["{$r->city_id}|{$r->product_id}|" . (int) $r->variant_id] = true;
        }

        return $out;
    }

    /** §97: lo aprobado, comprado, recibido y trasladado, junto al consumo real y lo que quedó */
    private function followUp(int $forecastId, array $rows, bool $stockKnown): array
    {
        $purchases = DB::table('forecast_purchase_recommendations as r')->leftJoin('inventory_purchases as p', 'p.id', '=', 'r.inventory_purchase_id')
            ->where('r.forecast_id', $forecastId)
            ->get(['r.recommended_qty', 'r.approved_qty', 'p.status', 'p.quantity', 'p.expected_quantity']);
        $bought = 0;
        $received = 0;
        foreach ($purchases as $p) {
            if ($p->status === null || $p->status === InventoryPurchase::CANCELLED) {
                continue;
            }
            $bought += (int) ($p->expected_quantity ?? $p->quantity);
            $received += $p->status === InventoryPurchase::RECEIVED ? (int) $p->quantity : 0;
        }
        $transfers = DB::table('forecast_transfer_recommendations as r')->leftJoin('inventory_transfers as t', 't.id', '=', 'r.inventory_transfer_id')
            ->where('r.forecast_id', $forecastId)->get(['r.quantity', 'r.approved_qty', 't.status']);

        return [
            'purchase_recommended' => (int) $purchases->sum('recommended_qty'),
            'purchase_approved' => (int) $purchases->sum('approved_qty'),
            'purchase_bought' => $bought,
            'purchase_received' => $received,
            'transfer_recommended' => (int) $transfers->sum('quantity'),
            'transfer_approved' => (int) $transfers->sum('approved_qty'),
            'transfer_done' => (int) $transfers->where('status', InventoryTransfer::RECEIVED)->sum('approved_qty'),
            'actual_units' => round(array_sum(array_column($rows, 'actual')), 2),
            'end_stock' => $stockKnown ? (int) DB::table('inventory_forecast_lines')->where('forecast_id', $forecastId)->sum('end_stock') : null,
            'stockouts' => count(array_filter($rows, fn ($r) => $r['stockout'])),
            'excess_units' => array_sum(array_map(fn ($r) => (int) ($r['excess'] ?? 0), $rows)),
        ];
    }

    private function pct(float $v): string
    {
        return number_format(abs($v), abs($v) < 10 ? 1 : 0, ',', '.');
    }
}
