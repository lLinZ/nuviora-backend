<?php

namespace App\Services\Inventory;

use App\Constants\OrderStatus;
use App\Models\InventoryPurchase;
use App\Models\InventoryTransfer;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras de stock del Módulo 5 de Fran (§14, R1), por almacén + producto + variante, cada una por separado:
 * - disponible: lo que se puede vender hoy (lo que el sistema ya mostraba, menos lo que está en revisión);
 * - en revisión: piezas dañadas o de cambios por revisar (Módulo 4, R8);
 * - reservado: lo apartado por órdenes que todavía no están entregadas. Esas piezas ya no cuentan en el almacén, pero
 *   siguen ahí hasta que se entregan (R1);
 * - físico: lo que hay de verdad en el almacén = lo del almacén + lo reservado;
 * - en tránsito: lo que viene en camino hacia este almacén desde otro (§15);
 * - por recibir: compras al proveedor que todavía no llegan, con la fecha más próxima (§16).
 * El almacén virtual "En tránsito" no sale como fila: lo suyo aparece como "en tránsito" del destino.
 */
class StockStatus
{
    /**
     * @return Collection<int, array{warehouse_id:int, warehouse:string, city_id:?int, product_id:int, product:string,
     *     variant_id:?int, variant:?string, physical:int, reserved:int, available:int, review:int, in_transit:int,
     *     incoming:int, incoming_next_at:?string}>
     */
    public function rows(?int $warehouseId = null, ?int $productId = null): Collection
    {
        $transit = Warehouse::transitId();
        $rows = [];
        $row = function (int $w, int $p, ?int $v) use (&$rows) {
            $key = "{$w}|{$p}|" . ($v ?? 0);
            $rows[$key] ??= ['warehouse_id' => $w, 'product_id' => $p, 'variant_id' => $v, 'stock' => 0, 'review' => 0,
                'reserved' => 0, 'in_transit' => 0, 'incoming' => 0, 'incoming_next_at' => null];

            return $key;
        };

        // Lo del almacén, por variante, y "sin variante" = el total menos lo repartido
        $variants = DB::table('inventory_variants')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->where('warehouse_id', '<>', $transit)
            ->get(['warehouse_id', 'product_id', 'variant_id', 'quantity', 'defective_stock'])
            ->groupBy(fn ($r) => "{$r->warehouse_id}|{$r->product_id}");
        $inventories = DB::table('inventories')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->where('warehouse_id', '<>', $transit)
            ->get(['warehouse_id', 'product_id', 'quantity', 'defective_stock']);
        foreach ($inventories as $inv) {
            $byVariant = $variants["{$inv->warehouse_id}|{$inv->product_id}"] ?? collect();
            foreach ($byVariant as $iv) {
                $key = $row((int) $iv->warehouse_id, (int) $iv->product_id, (int) $iv->variant_id);
                $rows[$key]['stock'] += (int) $iv->quantity;
                $rows[$key]['review'] += (int) $iv->defective_stock;
            }
            $restStock = (int) $inv->quantity - (int) $byVariant->sum('quantity');
            $restReview = (int) $inv->defective_stock - (int) $byVariant->sum('defective_stock');
            if ($restStock !== 0 || $restReview !== 0 || $byVariant->isEmpty()) {
                $key = $row((int) $inv->warehouse_id, (int) $inv->product_id, null);
                $rows[$key]['stock'] += $restStock;
                $rows[$key]['review'] += $restReview;
            }
        }

        foreach ($this->reserved($warehouseId, $productId) as $r) {
            $rows[$row((int) $r->warehouse_id, (int) $r->product_id, $r->variant_id ? (int) $r->variant_id : null)]['reserved'] += (int) $r->held;
        }

        $inTransit = DB::table('inventory_transfer_lines as l')
            ->join('inventory_transfers as t', 't.id', '=', 'l.transfer_id')
            ->where('t.status', InventoryTransfer::IN_TRANSIT)
            ->when($warehouseId, fn ($q) => $q->where('t.to_warehouse_id', $warehouseId))
            ->when($productId, fn ($q) => $q->where('l.product_id', $productId))
            ->groupBy('t.to_warehouse_id', 'l.product_id', 'l.variant_id')
            ->selectRaw('t.to_warehouse_id AS warehouse_id, l.product_id, l.variant_id, SUM(l.quantity) AS qty')
            ->get();
        foreach ($inTransit as $t) {
            $rows[$row((int) $t->warehouse_id, (int) $t->product_id, $t->variant_id ? (int) $t->variant_id : null)]['in_transit'] += (int) $t->qty;
        }

        $pending = InventoryPurchase::where('status', InventoryPurchase::PENDING)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->get(['id', 'warehouse_id', 'product_id', 'quantity', 'expected_variants', 'expected_at']);
        foreach ($pending as $purchase) {
            $split = array_map('intval', (array) ($purchase->expected_variants ?? []));
            $parts = $split;
            $rest = (int) $purchase->quantity - array_sum($split);
            if ($rest > 0) {
                $parts[0] = $rest;
            }
            foreach ($parts as $variantId => $qty) {
                $key = $row((int) $purchase->warehouse_id, (int) $purchase->product_id, (int) $variantId ?: null);
                $rows[$key]['incoming'] += $qty;
                $at = $purchase->expected_at?->format('Y-m-d');
                if ($at && ($rows[$key]['incoming_next_at'] === null || $at < $rows[$key]['incoming_next_at'])) {
                    $rows[$key]['incoming_next_at'] = $at;
                }
            }
        }

        $warehouses = DB::table('warehouses')->whereIn('id', array_unique(array_column($rows, 'warehouse_id')))->get(['id', 'name', 'city_id'])->keyBy('id');
        $products = DB::table('products')->whereIn('id', array_unique(array_column($rows, 'product_id')))
            ->get(['id', 'title', 'showable_name'])->keyBy('id');
        $variantTitles = DB::table('product_variants')->whereIn('id', array_filter(array_column($rows, 'variant_id')))->pluck('title', 'id');

        return collect($rows)->map(function ($r) use ($warehouses, $products, $variantTitles) {
            $w = $warehouses[$r['warehouse_id']] ?? null;
            $p = $products[$r['product_id']] ?? null;

            return [
                'warehouse_id' => $r['warehouse_id'],
                'warehouse' => $w?->name ?? "#{$r['warehouse_id']}",
                'city_id' => $w?->city_id !== null ? (int) $w->city_id : null,
                'product_id' => $r['product_id'],
                'product' => $p ? ($p->showable_name ?: $p->title) : "#{$r['product_id']}",
                'variant_id' => $r['variant_id'],
                'variant' => $r['variant_id'] ? ($variantTitles[$r['variant_id']] ?? null) : null,
                'physical' => $r['stock'] + $r['reserved'],
                'reserved' => $r['reserved'],
                'available' => $r['stock'] - $r['review'],
                'review' => $r['review'],
                'in_transit' => $r['in_transit'],
                'incoming' => $r['incoming'],
                'incoming_next_at' => $r['incoming_next_at'],
            ];
        })
            ->filter(fn ($r) => $r['physical'] || $r['available'] || $r['review'] || $r['in_transit'] || $r['incoming'])
            ->sortBy([['warehouse', 'asc'], ['product', 'asc'], ['variant', 'asc']])
            ->values();
    }

    /**
     * Lo apartado por órdenes que todavía no están entregadas: lo que salió por sus movimientos menos lo que volvió, por
     * el almacén de donde salió. Una orden entregada ya consumió su pieza; una cancelada la devolvió.
     */
    public function reserved(?int $warehouseId = null, ?int $productId = null): Collection
    {
        $delivered = DB::table('statuses')->where('description', OrderStatus::ENTREGADO)->value('id');
        $warehouse = "CASE WHEN m.movement_type = 'out' THEN m.from_warehouse_id ELSE m.to_warehouse_id END";

        return DB::table('inventory_movements as m')
            ->join('orders as o', 'o.id', '=', 'm.reference_id')
            ->where('m.reference_type', OrderStock::REFERENCE)
            ->where('m.status', 'completed')
            ->whereIn('m.movement_type', ['out', 'in'])
            ->where(fn ($q) => $q->whereNull('o.status_id')->orWhere('o.status_id', '<>', (int) $delivered))
            ->when($productId, fn ($q) => $q->where('m.product_id', $productId))
            ->when($warehouseId, fn ($q) => $q->whereRaw("{$warehouse} = ?", [$warehouseId]))
            ->groupByRaw("{$warehouse}, m.product_id, m.variant_id")
            ->selectRaw("{$warehouse} AS warehouse_id, m.product_id, m.variant_id,
                SUM(CASE WHEN m.movement_type = 'out' THEN m.quantity ELSE -m.quantity END) AS held")
            ->havingRaw("SUM(CASE WHEN m.movement_type = 'out' THEN m.quantity ELSE -m.quantity END) <> 0")
            ->get();
    }
}
