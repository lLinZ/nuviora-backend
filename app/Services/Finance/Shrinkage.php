<?php

namespace App\Services\Finance;

use App\Http\Controllers\DefectiveStockController;
use App\Models\InventoryMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Merma (Módulo 4 de Fran, entrega 2d; R3, R8): lo que se da de baja después de revisarlo ("Dar de baja" una pieza en
 * revisión). Cuenta en la fecha de la baja, al costo de su lote más su logística (§14), y va a "otros costos variables
 * atribuibles" del producto y la ciudad del almacén (§47), y a la V del CPA (§64: "otros costes variables").
 * - Una pieza que volvió de un cambio está a $0 (su costo ya se contó en la orden): darla de baja no es merma.
 * - Un ajuste de stock no es merma: es una corrección de conteo (Fran, 2026-10-14).
 * - Las salidas por muestra, regalo o uso interno (Módulo 5, §94) cuentan como gasto igual que la merma (R4).
 * - Si la pieza no tenía lote o su lote no tiene costo, se toma el costo de catálogo y se marca como provisional.
 */
class Shrinkage
{
    /**
     * @return array{items: array<int, array{product_id: int, city_id: ?int, units: int, amount_usd: float, provisional_units: int}>,
     *               total: float, units: int, provisional_units: int}
     */
    public function forRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('inventory_lot_allocations as a')
            ->join('inventory_movements as m', 'm.id', '=', 'a.inventory_movement_id')
            ->leftJoin('inventory_lot_layers as l', 'l.id', '=', 'a.layer_id')
            ->leftJoin('inventory_lots as lot', 'lot.id', '=', 'l.lot_id')
            ->leftJoin('products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
            ->whereIn('m.reference_type', [DefectiveStockController::REFERENCE, InventoryMovement::WRITE_OFF_REFERENCE])
            ->where('m.movement_type', 'out')
            ->where('a.quantity', '<', 0)
            ->whereBetween('m.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('a.product_id', 'w.city_id')
            ->selectRaw('a.product_id, w.city_id, SUM(-a.quantity) AS units,
                SUM(-a.quantity * (COALESCE(lot.unit_cost_usd, p.cost_usd, 0) + COALESCE(l.landed_cost_per_unit, 0))) AS cost,
                SUM(CASE WHEN lot.unit_cost_usd IS NULL THEN -a.quantity ELSE 0 END) AS provisional_units')
            ->get();

        $items = $rows->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'city_id' => $r->city_id !== null ? (int) $r->city_id : null,
            'units' => (int) $r->units,
            'amount_usd' => round((float) $r->cost, 2),
            'provisional_units' => (int) $r->provisional_units,
        ])->values()->all();

        return [
            'items' => $items,
            'total' => round(array_sum(array_column($items, 'amount_usd')), 2),
            'units' => array_sum(array_column($items, 'units')),
            'provisional_units' => array_sum(array_column($items, 'provisional_units')),
        ];
    }
}
