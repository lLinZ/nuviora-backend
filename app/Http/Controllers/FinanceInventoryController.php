<?php

namespace App\Http\Controllers;

use App\Models\FinancialAuditLog;
use App\Models\InventoryLot;
use App\Models\InventoryTransferTrip;
use App\Models\Setting;
use App\Services\Finance\LotLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finanzas › Inventario (Módulo 4 de Fran, entrega 2e; §9 a §17, R3, R5, R8). Solo el Admin (§81, por la ruta).
 * - Valor del inventario a costo FIFO: lo que queda de cada lote, a su costo más su logística (§11, §14).
 * - Lotes (§9, §12): por producto y variante, con su origen, proveedor, unidades y dónde están.
 * - Lotes por completar: los que entraron sin costo de compra o sin costo; el Admin les pone el costo (R5).
 * - En revisión (R3, R8), ajustes de conteo del período (Fran, 2026-10-14), viajes (§13) y el cuadre con el stock.
 */
class FinanceInventoryController extends Controller
{
    public const KIND_LABEL = [
        InventoryLot::KIND_INITIAL => 'Inicial (corte)',
        InventoryLot::KIND_PURCHASE => 'Compra',
        InventoryLot::KIND_PENDING_COST => 'Por completar',
        InventoryLot::KIND_RECOVERED => 'De un cambio',
        InventoryLot::KIND_ADJUSTMENT => 'Ajuste de conteo',
    ];

    public function index(Request $request, LotLedger $ledger): JsonResponse
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'product_id' => 'nullable|integer']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->endOfDay();
        $productName = 'COALESCE(NULLIF(p.showable_name, \'\'), p.title)';

        // Valor del inventario: lo que queda de cada capa, a su costo más su logística
        $value = DB::table('inventory_lot_layers as l')->join('inventory_lots as lot', 'lot.id', '=', 'l.lot_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')->where('l.quantity', '>', 0)
            ->groupBy('l.product_id', 'p.showable_name', 'p.title', 'l.state')
            ->selectRaw("l.product_id, {$productName} AS product, l.state, SUM(l.quantity) AS units,
                SUM(l.quantity * COALESCE(lot.unit_cost_usd, 0)) AS goods, SUM(l.quantity * l.landed_cost_per_unit) AS logistics,
                SUM(CASE WHEN lot.unit_cost_usd IS NULL THEN l.quantity ELSE 0 END) AS without_cost")
            ->get();
        $products = $value->groupBy('product_id')->map(function ($rows, $productId) {
            $available = $rows->firstWhere('state', 'available');
            $review = $rows->firstWhere('state', 'review');
            $units = (int) ($available->units ?? 0);
            $goods = (float) ($available->goods ?? 0);
            $logistics = (float) ($available->logistics ?? 0);

            return [
                'product_id' => (int) $productId,
                'product' => $rows->first()->product,
                'units' => $units,
                'value' => round($goods + $logistics, 2),
                'logistics' => round($logistics, 2),
                'avg_unit_cost' => $units > 0 ? round(($goods + $logistics) / $units, 4) : null,
                'without_cost_units' => (int) ($available->without_cost ?? 0),
                'review_units' => (int) ($review->units ?? 0),
                'review_value' => round((float) ($review->goods ?? 0) + (float) ($review->logistics ?? 0), 2),
            ];
        })->sortByDesc('value')->values();

        $byWarehouse = DB::table('inventory_lot_layers as l')->join('inventory_lots as lot', 'lot.id', '=', 'l.lot_id')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->where('l.quantity', '>', 0)->where('l.state', 'available')
            ->groupBy('w.id', 'w.name')
            ->selectRaw('w.id, w.name, SUM(l.quantity) AS units, SUM(l.quantity * (COALESCE(lot.unit_cost_usd, 0) + l.landed_cost_per_unit)) AS value')
            ->orderByDesc('value')->get()->map(fn ($r) => ['warehouse_id' => (int) $r->id, 'warehouse' => $r->name, 'units' => (int) $r->units,
                'value' => round((float) $r->value, 2)]);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'initialized' => LotLedger::initialized(),
            'started_at' => Setting::get(LotLedger::STARTED_KEY),
            'totals' => [
                'units' => $products->sum('units'),
                'value' => round($products->sum('value'), 2),
                'logistics' => round($products->sum('logistics'), 2),
                'without_cost_units' => $products->sum('without_cost_units'),
                'review_units' => $products->sum('review_units'),
                'review_value' => round($products->sum('review_value'), 2),
            ],
            'products' => $products,
            'warehouses' => $byWarehouse,
            'pending' => $this->pendingLots(),
            'lots' => $this->lots($data['product_id'] ?? null),
            'review' => $this->review(),
            'adjustments' => $this->adjustments($from, $to),
            'trips' => [
                'count' => InventoryTransferTrip::whereNull('voided_at')->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])->count(),
                'units' => (int) InventoryTransferTrip::whereNull('voided_at')->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])->sum('units'),
                'total_usd' => round((float) InventoryTransferTrip::whereNull('voided_at')->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])->sum('total_usd'), 2),
            ],
            'check' => $this->checkSummary($ledger),
        ]);
    }

    /**
     * R5: el costo de un lote que entró sin costo de compra (lote inicial sin costo de catálogo, ingreso sin compra o
     * corrección de conteo). Las compras se corrigen en la compra (Proveedores) y las piezas de un cambio están a $0.
     */
    public function updateCost(Request $request, InventoryLot $lot): JsonResponse
    {
        $data = $request->validate([
            'unit_cost_usd' => 'required|numeric|min:0.0001|max:1000000',
            'reason' => 'required|string|max:500',
        ], ['unit_cost_usd.required' => 'Escribe el costo por unidad, en USD.', 'reason.required' => 'Escribe de dónde sale el costo: queda en el historial.']);
        if ($lot->kind === InventoryLot::KIND_PURCHASE) {
            return response()->json(['message' => 'Es el lote de una compra: se corrige en la compra (Finanzas › Proveedores), así también cambia la deuda.'], 422);
        }
        if ($lot->kind === InventoryLot::KIND_RECOVERED) {
            return response()->json(['message' => 'Es una pieza que volvió de un cambio: va a $0 porque su costo ya se contó en la venta.'], 422);
        }
        $before = ['unit_cost_usd' => $lot->unit_cost_usd, 'cost_source' => $lot->cost_source];
        $lot->update(['unit_cost_usd' => round((float) $data['unit_cost_usd'], 4), 'cost_source' => 'manual']);
        FinancialAuditLog::record('inventory_lot_cost', 'inventory_lot', $lot->id, $before,
            ['unit_cost_usd' => $lot->unit_cost_usd, 'cost_source' => 'manual'], $data['reason']);

        return response()->json(['message' => 'Costo guardado. Las órdenes que vendieron unidades de este lote se recalculan en un minuto.']);
    }

    /** Lotes sin costo o con el de catálogo provisional ("por completar"), con lo que queda y lo que ya se vendió. */
    private function pendingLots(): array
    {
        return $this->lotQuery()
            ->where(fn ($q) => $q->whereNull('lot.unit_cost_usd')
                ->orWhere(fn ($w) => $w->where('lot.kind', InventoryLot::KIND_PENDING_COST)->where(fn ($c) => $c->whereNull('lot.cost_source')->orWhere('lot.cost_source', '<>', 'manual'))))
            ->where('lot.kind', '<>', InventoryLot::KIND_RECOVERED)
            ->orderByDesc('lot.received_at')->limit(300)->get()->map(fn ($r) => $this->lotRow($r))->all();
    }

    private function lots(?int $productId): array
    {
        return $this->lotQuery()->when($productId, fn ($q) => $q->where('lot.product_id', $productId))
            ->orderByDesc('lot.received_at')->orderByDesc('lot.id')->limit(300)->get()->map(fn ($r) => $this->lotRow($r))->all();
    }

    private function lotQuery()
    {
        return DB::table('inventory_lots as lot')
            ->join('products as p', 'p.id', '=', 'lot.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'lot.variant_id')
            ->leftJoin('inventory_purchases as pu', 'pu.id', '=', 'lot.purchase_id')
            ->leftJoin('suppliers as su', 'su.id', '=', 'pu.supplier_id')
            ->leftJoin('inventory_movements as m', 'm.id', '=', 'lot.source_movement_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->select('lot.id', 'lot.product_id', 'lot.kind', 'lot.initial_quantity', 'lot.unit_cost_usd', 'lot.cost_source', 'lot.received_at', 'lot.notes',
                'lot.purchase_id', 'p.title as product', 'p.showable_name', 'v.title as variant', 'su.name as supplier', 'm.notes as movement_notes', 'u.names as user')
            ->selectSub(fn ($q) => $q->from('inventory_lot_layers')->whereColumn('lot_id', 'lot.id')->selectRaw('COALESCE(SUM(quantity), 0)'), 'remaining')
            ->selectSub(fn ($q) => $q->from('inventory_lot_layers')->whereColumn('lot_id', 'lot.id')->where('quantity', '>', 0)
                ->selectRaw('COALESCE(SUM(quantity * landed_cost_per_unit) / NULLIF(SUM(quantity), 0), 0)'), 'landed');
    }

    private function lotRow(object $r): array
    {
        $where = DB::table('inventory_lot_layers as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->where('l.lot_id', $r->id)
            ->where('l.quantity', '>', 0)->groupBy('w.name', 'l.state')->selectRaw('w.name, l.state, SUM(l.quantity) AS units')->get()
            ->map(fn ($w) => ['warehouse' => $w->name, 'state' => $w->state, 'units' => (int) $w->units])->values();

        return [
            'id' => (int) $r->id,
            'product_id' => (int) $r->product_id,
            'product' => $r->showable_name ?: $r->product,
            'variant' => $r->variant,
            'kind' => $r->kind,
            'kind_label' => self::KIND_LABEL[$r->kind] ?? $r->kind,
            'supplier' => $r->supplier,
            'purchase_id' => $r->purchase_id ? (int) $r->purchase_id : null,
            'received_at' => substr((string) $r->received_at, 0, 16),
            'initial' => (int) $r->initial_quantity,
            'remaining' => (int) $r->remaining,
            'unit_cost' => $r->unit_cost_usd !== null ? (float) $r->unit_cost_usd : null,
            'cost_source' => $r->cost_source,
            'landed' => round((float) $r->landed, 4),
            'notes' => $r->notes,
            'origin' => $r->movement_notes,
            'user' => $r->user,
            'where' => $where,
        ];
    }

    /** R3, R8: lo que está en revisión, por producto y almacén, con su costo (las de un cambio, a $0). */
    private function review(): array
    {
        return DB::table('inventory_lot_layers as l')->join('inventory_lots as lot', 'lot.id', '=', 'l.lot_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.state', 'review')->where('l.quantity', '>', 0)
            ->groupBy('l.product_id', 'p.showable_name', 'p.title', 'w.name')
            ->selectRaw("l.product_id, COALESCE(NULLIF(p.showable_name, ''), p.title) AS product, w.name AS warehouse, SUM(l.quantity) AS units,
                SUM(l.quantity * (COALESCE(lot.unit_cost_usd, 0) + l.landed_cost_per_unit)) AS value,
                SUM(CASE WHEN lot.kind = 'recovered' THEN l.quantity ELSE 0 END) AS from_exchange")
            ->orderByDesc('value')->get()->map(fn ($r) => [
                'product_id' => (int) $r->product_id, 'product' => $r->product, 'warehouse' => $r->warehouse, 'units' => (int) $r->units,
                'value' => round((float) $r->value, 2), 'from_exchange' => (int) $r->from_exchange,
            ])->all();
    }

    /**
     * Ajustes de conteo del período (Fran, 2026-10-14): lo que subió o bajó cada ajuste, en unidades y al costo de sus
     * lotes. No es merma ni gasto: se muestra para ver si las diferencias con las agencias se repiten.
     */
    private function adjustments(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('inventory_lot_allocations as a')
            ->join('inventory_movements as m', 'm.id', '=', 'a.inventory_movement_id')
            ->leftJoin('inventory_lot_layers as l', 'l.id', '=', 'a.layer_id')
            ->leftJoin('inventory_lots as lot', 'lot.id', '=', 'l.lot_id')
            ->leftJoin('products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.movement_type', 'adjustment')->whereNull('m.reference_type')
            ->whereBetween('m.created_at', [$from, $to])
            ->groupBy('m.id', 'm.created_at', 'm.notes', 'p.showable_name', 'p.title', 'w.name', 'u.names')
            ->selectRaw("m.id, m.created_at, m.notes, COALESCE(NULLIF(p.showable_name, ''), p.title) AS product, w.name AS warehouse, u.names AS user,
                SUM(a.quantity) AS units, SUM(a.quantity * (COALESCE(lot.unit_cost_usd, p.cost_usd, 0) + COALESCE(l.landed_cost_per_unit, 0))) AS value")
            ->orderByDesc('m.created_at')->get();
        $up = $rows->where('units', '>', 0);
        $down = $rows->where('units', '<', 0);

        return [
            'up_units' => (int) $up->sum('units'),
            'up_value' => round((float) $up->sum('value'), 2),
            'down_units' => (int) -$down->sum('units'),
            'down_value' => round((float) -$down->sum('value'), 2),
            'rows' => $rows->take(200)->map(fn ($r) => [
                'movement_id' => (int) $r->id, 'date' => substr((string) $r->created_at, 0, 16), 'product' => $r->product, 'warehouse' => $r->warehouse,
                'units' => (int) $r->units, 'value' => round((float) $r->value, 2), 'notes' => $r->notes, 'user' => $r->user,
            ])->values()->all(),
        ];
    }

    /** El cuadre de los lotes con el stock (finance:lots --check), resumido. */
    private function checkSummary(LotLedger $ledger): array
    {
        if (!LotLedger::initialized()) {
            return ['differences' => 0, 'pending' => 0];
        }
        $check = $ledger->check();

        return ['differences' => count($check['rows']), 'pending' => $check['pending']];
    }
}
