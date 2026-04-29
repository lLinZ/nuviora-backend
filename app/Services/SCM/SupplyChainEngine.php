<?php

namespace App\Services\SCM;

use App\Models\Inventory;
use App\Models\OrderProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SupplyChainEngine — Motor de Inteligencia de Inventario
 *
 * Implementa las "Reglas de Oro" del Plan Maestro SCM:
 *   1. Stock Útil = stock_físico - reservado - defectuoso - bloqueado
 *   2. Compra Real = compra_calculada / (1 - defect_percentage)
 *   3. Históricos limpios (excluye días con stock 0)
 *   4. KPI Días de Cobertura = stock_útil / demanda_diaria
 */
class SupplyChainEngine
{
    /**
     * Retorna el análisis SCM completo de todos los productos en inventario.
     * Si se pasa $productId, retorna solo ese producto.
     */
    public function analyze(?int $productId = null): array
    {
        $query = Inventory::with(['product', 'warehouse']);

        if ($productId) {
            $query->where('product_id', $productId);
        }

        $inventories = $query->get();

        $results = [];

        foreach ($inventories as $inv) {
            $product = $inv->product;
            if (!$product) continue;

            $leadTime        = $product->lead_time_days ?? 3;
            $defectPct       = ($product->defect_percentage ?? 0) / 100;
            $usefulStock     = $inv->useful_stock;

            // ─── Promedios Adaptativos (Regla de Oro #3) ──────────────────────
            $dailyDemand = $this->calculateDailyDemand($product->id);

            // ─── Días de Cobertura (Regla de Oro #4) ──────────────────────────
            $daysCoverage = $dailyDemand > 0
                ? round($usefulStock / $dailyDemand, 1)
                : null;

            // ─── Semáforo de Prioridad ─────────────────────────────────────────
            // Rojo: cobertura < lead_time (riesgo de quiebre de stock)
            // Naranja: cobertura < lead_time * 1.5
            // Amarillo: cobertura < lead_time * 2.5
            // Verde: cobertura >= lead_time * 2.5
            $priority = $this->getPriority($daysCoverage, $leadTime);

            // ─── Compra Sugerida (Regla de Oro #2) ────────────────────────────
            $projectionDays      = 30;
            $safetyStock         = $leadTime * $dailyDemand;
            $targetStock         = ($dailyDemand * ($projectionDays + $leadTime)) + $safetyStock;
            $rawPurchaseQty      = max(0, $targetStock - $usefulStock);
            // Ajuste por merma: compra_real = compra_calculada / (1 - pct_defecto)
            $adjustedPurchaseQty = $defectPct > 0 && $defectPct < 1
                ? ceil($rawPurchaseQty / (1 - $defectPct))
                : ceil($rawPurchaseQty);

            $results[] = [
                'product_id'         => $product->id,
                'product_name'       => $product->showable_name ?? $product->name,
                'sku'                => $product->sku,
                'image'              => $product->image,
                'warehouse_id'       => $inv->warehouse_id,
                'warehouse_name'     => $inv->warehouse->name ?? 'N/A',
                // Stock desglosado
                'stock_physical'     => $inv->quantity,
                'stock_reserved'     => $inv->reserved_stock,
                'stock_defective'    => $inv->defective_stock,
                'stock_blocked'      => $inv->blocked_stock,
                'stock_useful'       => $usefulStock,
                // Métricas de producto
                'lead_time_days'     => $leadTime,
                'defect_percentage'  => $product->defect_percentage ?? 0,
                // KPIs calculados
                'daily_demand'       => round($dailyDemand, 2),
                'days_coverage'      => $daysCoverage,
                'safety_stock'       => round($safetyStock),
                'target_stock'       => round($targetStock),
                'purchase_suggested' => $adjustedPurchaseQty,
                // Semáforo
                'priority'           => $priority,
            ];
        }

        // Ordenar por prioridad: Rojo primero
        usort($results, function ($a, $b) {
            $order = ['red' => 0, 'orange' => 1, 'yellow' => 2, 'green' => 3, 'gray' => 4];
            return ($order[$a['priority']] ?? 5) <=> ($order[$b['priority']] ?? 5);
        });

        return $results;
    }

    /**
     * Calcula la demanda diaria promedio usando histórico limpio.
     *
     * Regla de Oro #3: EXCLUIR días con stock 0 para no sesgar el promedio.
     * Lógica adaptativa según días de historial disponibles:
     *   < 7 días  → promedio simple desde inicio
     *   7–20 días → (0.65 * prom_7d) + (0.35 * prom_total)
     *   ≥ 21 días → (0.40 * 7d) + (0.35 * 14d) + (0.25 * 30d)
     */
    public function calculateDailyDemand(int $productId): float
    {
        // Agrupamos ventas por fecha usando order_products + orders
        $salesByDay = OrderProduct::where('product_id', $productId)
            ->join('orders', 'order_products.order_id', '=', 'orders.id')
            ->where('orders.status_id', 15) // SOLO VENTAS ENTREGADAS
            ->whereNotNull('orders.processed_at')
            ->where('orders.processed_at', '>=', now()->subDays(60))
            ->selectRaw('DATE(orders.processed_at) as sale_date, SUM(order_products.quantity) as total_sold')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->pluck('total_sold', 'sale_date')
            ->toArray();

        if (empty($salesByDay)) {
            return 0.0;
        }

        $firstDate  = now()->subDays(count($salesByDay));
        $totalDays  = count($salesByDay);

        $avg7  = $this->avgForLastNDays($salesByDay, 7);
        $avg14 = $this->avgForLastNDays($salesByDay, 14);
        $avg30 = $this->avgForLastNDays($salesByDay, 30);
        $avgAll = array_sum($salesByDay) / max(1, count($salesByDay));

        if ($totalDays < 7) {
            return $avgAll;
        } elseif ($totalDays < 21) {
            return (0.65 * $avg7) + (0.35 * $avgAll);
        } else {
            return (0.40 * $avg7) + (0.35 * $avg14) + (0.25 * $avg30);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function avgForLastNDays(array $salesByDay, int $n): float
    {
        $cutoff = now()->subDays($n)->toDateString();
        $slice  = array_filter($salesByDay, fn($date) => $date >= $cutoff, ARRAY_FILTER_USE_KEY);

        return count($slice) > 0
            ? array_sum($slice) / $n   // dividir entre n días (no entre días con venta)
            : 0.0;
    }

    private function getPriority(?float $daysCoverage, int $leadTime): string
    {
        if ($daysCoverage === null) return 'gray';           // sin datos de demanda

        if ($daysCoverage < $leadTime)         return 'red';     // URGENTE: quiebre inminente
        if ($daysCoverage < $leadTime * 1.5)   return 'orange';  // ALERTA
        if ($daysCoverage < $leadTime * 2.5)   return 'yellow';  // PRECAUCIÓN
        return 'green';                                           // OK
    }
}
