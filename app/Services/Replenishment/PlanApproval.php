<?php

namespace App\Services\Replenishment;

use App\Models\InventoryAuditLog;
use App\Models\InventoryTransfer;
use App\Models\ProductCityAvailability;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Finance\InventoryPurchases;
use App\Services\Inventory\InventoryTransfers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprobar un plan de inventario (Módulo 5 de Fran, entrega 4; §21, §58, §59, §86, §89–§92, §122).
 * - El Admin aprueba todo o en parte: cambia cantidades, quita movimientos, cambia origen o destino (§89). Se guarda lo
 *   aprobado junto a lo recomendado.
 * - Cada compra aprobada se vuelve una compra "por recibir" (entrega 1): proveedor, cantidad, almacén de recepción y costo
 *   que el Admin confirma o cambia (§21, §86, §90). No se le manda nada al proveedor.
 * - Cada traslado aprobado se vuelve un traslado "Pendiente" (§92), agrupado por origen y destino. Los que reparten lo
 *   comprado quedan atados a su compra y no pueden salir hasta que se reciba (§58, §59).
 * - Un plan se aprueba una sola vez.
 */
class PlanApproval
{
    public function __construct(private InventoryPurchases $purchases, private InventoryTransfers $transfers)
    {
    }

    /**
     * @param array<int, array{id:int, qty:int, supplier_id?:?int, receiving_warehouse_id?:?int, unit_cost_usd?:?float, expected_at?:?string}> $purchaseInput
     * @param array<int, array{id:int, qty:int, from_warehouse_id?:?int, to_warehouse_id?:?int}> $transferInput
     */
    public function approve(int $forecastId, array $purchaseInput, array $transferInput): array
    {
        return DB::transaction(function () use ($forecastId, $purchaseInput, $transferInput) {
            $forecast = DB::table('inventory_forecasts')->where('id', $forecastId)->lockForUpdate()->first();
            abort_if(!$forecast, 404);
            if ($forecast->approved_at) {
                throw ValidationException::withMessages(['plan' => 'Este plan ya está aprobado. Genera uno nuevo si quieres otro.']);
            }
            $purchaseIn = collect($purchaseInput)->keyBy('id');
            $transferIn = collect($transferInput)->keyBy('id');
            $recPurchases = DB::table('forecast_purchase_recommendations')->where('forecast_id', $forecastId)->orderBy('id')->get();
            $recTransfers = DB::table('forecast_transfer_recommendations')->where('forecast_id', $forecastId)->orderBy('id')->get();
            $transit = Warehouse::transitId();
            $active = Warehouse::where('is_active', true)->where('id', '<>', $transit)->pluck('city_id', 'id');
            $note = "Plan de inventario #{$forecastId}";
            $summary = ['purchases' => [], 'transfers' => []];

            // ---- Compras (§90): una compra por recibir por producto + variante aprobado ----
            $createdBySku = []; // "producto|variante" => [purchase_id, receiving]
            foreach ($recPurchases as $rec) {
                $in = $purchaseIn->get($rec->id);
                $qty = max(0, (int) ($in['qty'] ?? 0));
                $supplierId = (int) ($in['supplier_id'] ?? $rec->supplier_id ?? 0) ?: null;
                $receiving = (int) ($in['receiving_warehouse_id'] ?? $rec->receiving_warehouse_id ?? 0) ?: null;
                $cost = isset($in['unit_cost_usd']) && $in['unit_cost_usd'] !== null && $in['unit_cost_usd'] !== '' ? (float) $in['unit_cost_usd'] : ($rec->unit_cost_usd !== null ? (float) $rec->unit_cost_usd : null);
                $purchaseId = null;
                $expected = null;
                if ($qty > 0) {
                    $label = DB::table('products')->where('id', $rec->product_id)->value('title');
                    if (!$supplierId || !Supplier::whereKey($supplierId)->exists()) {
                        throw ValidationException::withMessages(["purchases.{$rec->id}" => "Elige el proveedor de {$label}."]);
                    }
                    if (!$receiving || !isset($active[$receiving])) {
                        throw ValidationException::withMessages(["purchases.{$rec->id}" => "Elige en qué almacén se recibe {$label}."]);
                    }
                    if ($cost === null || $cost <= 0) {
                        throw ValidationException::withMessages(["purchases.{$rec->id}" => "Escribe el costo por unidad de {$label} (§86)."]);
                    }
                    $lead = (float) (DB::table('supplier_products')->where('supplier_id', $supplierId)->where('product_id', $rec->product_id)->value('lead_time_days') ?? 1);
                    $expected = $in['expected_at'] ?? date('Y-m-d', strtotime('+' . max(0, (int) ceil($lead)) . ' days'));
                    $purchase = $this->purchases->createPending([
                        'supplier_id' => $supplierId, 'product_id' => (int) $rec->product_id, 'warehouse_id' => $receiving, 'quantity' => $qty,
                        'variants' => $rec->variant_id ? [(int) $rec->variant_id => $qty] : null,
                        'total_amount' => round($qty * $cost, 2), 'currency' => 'USD', 'expected_at' => $expected, 'notes' => $note,
                    ]);
                    $purchaseId = $purchase->id;
                    $createdBySku["{$rec->product_id}|" . (int) $rec->variant_id] = ['id' => $purchaseId, 'receiving' => $receiving, 'qty' => $qty];
                }
                DB::table('forecast_purchase_recommendations')->where('id', $rec->id)->update([
                    'approved_qty' => $qty, 'approved_supplier_id' => $supplierId, 'approved_receiving_warehouse_id' => $receiving,
                    'approved_unit_cost_usd' => $cost, 'approved_expected_at' => $expected, 'inventory_purchase_id' => $purchaseId, 'updated_at' => now(),
                ]);
                $summary['purchases'][] = ['product_id' => $rec->product_id, 'variant_id' => $rec->variant_id, 'recommended' => $rec->recommended_qty, 'approved' => $qty];
            }

            // ---- Traslados (§92): agrupados por origen, destino y compra de la que dependen ----
            $groups = [];
            foreach ($recTransfers as $rec) {
                $in = $transferIn->get($rec->id);
                $qty = max(0, (int) ($in['qty'] ?? 0));
                $from = (int) ($in['from_warehouse_id'] ?? $rec->from_warehouse_id);
                $to = (int) ($in['to_warehouse_id'] ?? $rec->to_warehouse_id);
                $purchaseId = null;
                if ($qty > 0) {
                    if ($from === $to || !isset($active[$from]) || !isset($active[$to])) {
                        throw ValidationException::withMessages(["transfers.{$rec->id}" => 'El origen y el destino tienen que ser dos almacenes distintos y activos.']);
                    }
                    $cityTo = $active[$to];
                    if ($cityTo !== null && ProductCityAvailability::where('product_id', $rec->product_id)->where('city_id', $cityTo)->where('is_available', false)->exists()) {
                        throw ValidationException::withMessages(["transfers.{$rec->id}" => 'Ese producto no se vende en la ciudad de destino (§122).']);
                    }
                    if ($rec->depends_on_purchase) {
                        $created = $createdBySku["{$rec->product_id}|" . (int) $rec->variant_id] ?? null;
                        if (!$created) {
                            throw ValidationException::withMessages(["transfers.{$rec->id}" => 'Este traslado reparte mercancía de una compra que no aprobaste: quítalo o aprueba la compra.']);
                        }
                        // Si sale del almacén donde llega la compra, espera la recepción (§58); si el Admin cambió el origen, sale cuando quiera
                        $purchaseId = $created['receiving'] === $from ? $created['id'] : null;
                    }
                    $groups["{$from}|{$to}|" . ($purchaseId ?? 0)][] = ['rec' => $rec->id, 'product_id' => (int) $rec->product_id, 'variant_id' => $rec->variant_id ? (int) $rec->variant_id : null, 'quantity' => $qty];
                }
                DB::table('forecast_transfer_recommendations')->where('id', $rec->id)->update([
                    'approved_qty' => $qty, 'approved_from_warehouse_id' => $from, 'approved_to_warehouse_id' => $to, 'updated_at' => now(),
                ]);
                $summary['transfers'][] = ['product_id' => $rec->product_id, 'variant_id' => $rec->variant_id, 'from' => $from, 'to' => $to,
                    'recommended' => $rec->quantity, 'approved' => $qty];
            }
            foreach ($groups as $gk => $lines) {
                [$from, $to, $purchaseId] = array_map('intval', explode('|', $gk));
                $transfer = $this->transfers->create($from, $to, array_map(fn ($l) => array_diff_key($l, ['rec' => 1]), $lines), $note,
                    InventoryTransfer::PENDING, $purchaseId ?: null);
                DB::table('forecast_transfer_recommendations')->whereIn('id', array_column($lines, 'rec'))->update(['inventory_transfer_id' => $transfer->id]);
            }

            DB::table('inventory_forecasts')->where('id', $forecastId)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => Auth::id(), 'updated_at' => now()]);
            InventoryAuditLog::record('plan_approved', 'inventory_forecast', $forecastId, null, $summary);

            return $summary;
        });
    }
}
