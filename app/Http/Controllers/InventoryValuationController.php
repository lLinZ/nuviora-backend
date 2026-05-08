<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 – Financial Inventory Valuation
 *
 * All endpoints are READ-ONLY. No writes to any table.
 */
class InventoryValuationController extends Controller
{
    private function authorizeAdmin()
    {
        $user = Auth::user();
        if (!in_array($user?->role?->description, ['Admin', 'Gerente', 'Master'])) {
            abort(403, 'Solo administradores pueden ver la valoración financiera.');
        }
    }

    /**
     * GET /reports/inventory-valuation
     *
     * Returns current stock × weighted-average purchase cost for every
     * product/warehouse combination that has inventory.
     *
     * Also includes:
     *   - sale_price        (products.price)
     *   - base_cost_usd     (products.cost_usd — manual field)
     *   - avg_po_cost_usd   (weighted avg from received PO items)
     *   - effective_cost    (avg_po_cost_usd if available, else base_cost_usd)
     *   - total_value_usd   (quantity × effective_cost)
     *   - margin_usd        (sale_price − effective_cost)
     *   - margin_pct        (margin / sale_price × 100)
     */
    public function valuation(Request $request)
    {
        $this->authorizeAdmin();

        // --- Weighted average cost per product from received POs ---
        // Only uses items from received / partially-received orders.
        $avgCosts = PurchaseOrderItem::select(
                'product_id',
                DB::raw('SUM(quantity_received * unit_cost_usd) / NULLIF(SUM(quantity_received), 0) AS avg_po_cost_usd'),
                DB::raw('SUM(quantity_received) AS total_units_received'),
                DB::raw('MAX(updated_at) AS last_receipt_at')
            )
            ->whereHas('purchaseOrder', fn($q) => $q->whereIn('status', ['received', 'partial']))
            ->where('quantity_received', '>', 0)
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // --- All inventory records with product + warehouse ---
        $inventories = Inventory::with(['product', 'warehouse'])
            ->where('quantity', '>', 0)
            ->get();

        $rows = $inventories->map(function ($inv) use ($avgCosts) {
            $product     = $inv->product;
            if (!$product) return null;

            $qty         = (int) $inv->quantity;
            $salePrice   = (float) ($product->price ?? 0);
            $baseCost    = (float) ($product->cost_usd ?? 0);
            $avgPoData   = $avgCosts->get($product->id);
            $avgPoCost   = $avgPoData ? (float) $avgPoData->avg_po_cost_usd : null;

            // Prefer PO-derived cost; fall back to manual cost_usd
            $effectiveCost = $avgPoCost ?? $baseCost;

            $totalValue    = $qty * $effectiveCost;
            $marginUsd     = $salePrice > 0 ? $salePrice - $effectiveCost : null;
            $marginPct     = ($salePrice > 0 && $effectiveCost > 0)
                             ? (($salePrice - $effectiveCost) / $salePrice) * 100
                             : null;

            return [
                'product_id'          => $product->id,
                'product_name'        => $product->title ?? $product->showable_name ?? $product->name,
                'sku'                 => $product->sku,
                'warehouse_id'        => $inv->warehouse_id,
                'warehouse_name'      => $inv->warehouse?->name ?? '—',
                'quantity'            => $qty,
                'sale_price_usd'      => round($salePrice, 4),
                'base_cost_usd'       => round($baseCost, 4),
                'avg_po_cost_usd'     => $avgPoCost !== null ? round($avgPoCost, 4) : null,
                'effective_cost_usd'  => round($effectiveCost, 4),
                'cost_source'         => $avgPoCost !== null ? 'purchase_order' : 'manual',
                'total_value_usd'     => round($totalValue, 2),
                'margin_usd'          => $marginUsd !== null ? round($marginUsd, 4) : null,
                'margin_pct'          => $marginPct !== null ? round($marginPct, 2) : null,
                'total_units_received'=> $avgPoData ? (int) $avgPoData->total_units_received : 0,
                'last_receipt_at'     => $avgPoData?->last_receipt_at,
            ];
        })->filter()->values();

        // --- Summary ---
        $summary = [
            'total_products'        => $rows->pluck('product_id')->unique()->count(),
            'total_warehouses'      => $rows->pluck('warehouse_id')->unique()->count(),
            'total_units'           => $rows->sum('quantity'),
            'total_value_usd'       => round($rows->sum('total_value_usd'), 2),
            'products_with_po_cost' => $rows->where('cost_source', 'purchase_order')->count(),
            'products_with_manual_cost' => $rows->where('cost_source', 'manual')->count(),
            'avg_margin_pct'        => $rows->whereNotNull('margin_pct')->avg('margin_pct'),
        ];

        // --- By-warehouse totals ---
        $byWarehouse = $rows->groupBy('warehouse_name')->map(function ($group, $name) {
            return [
                'warehouse_name'  => $name,
                'total_products'  => $group->pluck('product_id')->unique()->count(),
                'total_units'     => $group->sum('quantity'),
                'total_value_usd' => round($group->sum('total_value_usd'), 2),
            ];
        })->values();

        return response()->json([
            'success'      => true,
            'summary'      => $summary,
            'by_warehouse' => $byWarehouse,
            'rows'         => $rows,
        ]);
    }

    /**
     * GET /reports/profitability
     *
     * Per-product P&L: sale_price vs effective_cost.
     * Aggregated across all warehouses.
     */
    public function profitability(Request $request)
    {
        $this->authorizeAdmin();

        // Avg PO cost per product
        $avgCosts = PurchaseOrderItem::select(
                'product_id',
                DB::raw('SUM(quantity_received * unit_cost_usd) / NULLIF(SUM(quantity_received), 0) AS avg_po_cost_usd')
            )
            ->whereHas('purchaseOrder', fn($q) => $q->whereIn('status', ['received', 'partial']))
            ->where('quantity_received', '>', 0)
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // Total stock per product across all warehouses
        $stockByProduct = Inventory::select('product_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $products = Product::all();

        $rows = $products->map(function ($p) use ($avgCosts, $stockByProduct) {
            $salePrice    = (float) ($p->price ?? 0);
            $baseCost     = (float) ($p->cost_usd ?? 0);
            $avgPo        = $avgCosts->get($p->id);
            $effectiveCost = $avgPo ? (float) $avgPo->avg_po_cost_usd : $baseCost;
            $stock        = $stockByProduct->get($p->id)?->total_qty ?? 0;
            $totalValue   = $stock * $effectiveCost;
            $marginUsd    = $salePrice > 0 ? $salePrice - $effectiveCost : null;
            $marginPct    = ($salePrice > 0 && $effectiveCost > 0)
                            ? (($salePrice - $effectiveCost) / $salePrice) * 100
                            : null;

            return [
                'product_id'       => $p->id,
                'product_name'     => $p->title ?? $p->showable_name ?? $p->name,
                'sku'              => $p->sku,
                'total_stock'      => (int) $stock,
                'sale_price_usd'   => round($salePrice, 4),
                'effective_cost_usd' => round($effectiveCost, 4),
                'cost_source'      => $avgPo ? 'purchase_order' : 'manual',
                'total_value_usd'  => round($totalValue, 2),
                'margin_usd'       => $marginUsd !== null ? round($marginUsd, 4) : null,
                'margin_pct'       => $marginPct !== null ? round($marginPct, 2) : null,
            ];
        })->filter(fn($r) => $r['sale_price_usd'] > 0 || $r['total_stock'] > 0)
          ->sortByDesc('total_value_usd')
          ->values();

        return response()->json([
            'success' => true,
            'data'    => $rows,
        ]);
    }
}
