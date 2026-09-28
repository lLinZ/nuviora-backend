<?php

namespace App\Services\Inventory;

use App\Constants\OrderStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\Product;
use App\Models\Status;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Stock de una orden (tarea 3a). Una sola regla para descontar y devolver:
 *  - Lo que la orden tiene fuera se lee de sus propios movimientos (reference Order), por almacén,
 *    producto y talla. Al devolver, vuelve exactamente eso y al almacén de donde salió.
 *  - sync() compara eso con lo que debería tener fuera según su estado, su agencia y sus productos, y
 *    registra solo la diferencia. Sirve igual para un cambio de estado, de agencia o de productos.
 * Las devoluciones (is_return) no mueven stock: el producto se queda con el cliente (Fran §7).
 */
class OrderStock
{
    public const REFERENCE = 'Order';
    /** Pieza defectuosa que la agencia retira en un cambio (Fran §8). No cuenta como stock de la orden. */
    public const DEFECTIVE_REFERENCE = 'DefectivePickup';

    /**
     * Lleva el stock de la orden a lo que corresponde. Devuelve los movimientos que hizo.
     *
     * @return array<int, array{type:string, warehouse_id:int, product_id:int, size:string, quantity:int}>
     */
    public function sync(Order $order): array
    {
        $status = Status::whereKey($order->status_id)->value('description');
        $held = $this->held($order);

        if ($order->is_return || in_array($status, OrderStatus::RETURN_STATUSES, true)) {
            $target = collect();
        } elseif (in_array($status, OrderStatus::DEDUCTION_STATUSES, true) || $held->isNotEmpty()) {
            $target = $this->target($order, $status, $held);
        } else {
            return [];
        }

        $ops = [];
        foreach ($target->keys()->merge($held->keys())->unique() as $key) {
            $delta = ($target[$key]['quantity'] ?? 0) - ($held[$key]['quantity'] ?? 0);
            if ($delta !== 0) {
                $row = $target[$key] ?? $held[$key];
                $ops[] = [
                    'type' => $delta > 0 ? 'out' : 'in',
                    'warehouse_id' => $row['warehouse_id'],
                    'product_id' => $row['product_id'],
                    'size' => $row['size'],
                    'quantity' => abs($delta),
                ];
            }
        }
        if (!$ops) {
            return [];
        }

        // Primero lo que vuelve y después lo que sale: si cambió la agencia, se lee como un traslado
        usort($ops, fn ($a, $b) => ($a['type'] === 'out') <=> ($b['type'] === 'out'));
        DB::transaction(function () use ($order, $ops, $status) {
            foreach ($ops as $op) {
                $this->move($order, $op, $status);
            }
        });
        $this->log($order, $ops, $status);

        return $ops;
    }

    /**
     * Al entregarse un cambio, la agencia se queda con la pieza que retiró. Entra a su almacén como
     * defectuosa (defective_stock): existe físicamente, pero no se puede vender hasta revisarla.
     */
    public function intakeDefective(Order $order): int
    {
        if (!$order->is_exchange) {
            return 0;
        }
        $done = InventoryMovement::where('reference_type', self::DEFECTIVE_REFERENCE)
            ->where('reference_id', $order->id)->exists();
        if ($done) {
            return 0;
        }

        $held = $this->held($order);
        $fallback = $order->resolveStockWarehouseId();
        $count = 0;

        DB::transaction(function () use ($order, $held, $fallback, &$count) {
            foreach ($order->products()->get(['product_id', 'size', 'quantity']) as $line) {
                $qty = (int) $line->quantity;
                $warehouseId = $held->firstWhere('product_id', $line->product_id)['warehouse_id'] ?? $fallback;
                if ($qty <= 0 || !$warehouseId) {
                    continue;
                }
                $inv = $this->lockedInventory($warehouseId, $line->product_id);
                DB::table('inventories')->where('id', $inv->id)->update([
                    'quantity' => $inv->quantity + $qty,
                    'defective_stock' => $inv->defective_stock + $qty,
                    'updated_at' => now(),
                ]);
                InventoryMovement::create([
                    'product_id' => $line->product_id,
                    'from_warehouse_id' => null,
                    'to_warehouse_id' => $warehouseId,
                    'quantity' => $qty,
                    'size' => $this->size($line->size) ?: null,
                    'movement_type' => 'in',
                    'status' => 'completed',
                    'reference_type' => self::DEFECTIVE_REFERENCE,
                    'reference_id' => $order->id,
                    'user_id' => Auth::id(),
                    'notes' => "Pieza retirada en el cambio #{$order->name}: defectuosa, pendiente de revisión",
                ]);
                $count += $qty;
            }
        });

        if ($count > 0) {
            $this->activity($order, "Stock: la agencia retiró {$count} pieza(s) defectuosa(s); quedan en su almacén como pendientes de revisión.");
        }

        return $count;
    }

    /**
     * Lo que la orden tiene fuera ahora, por almacén, producto y talla (salidas menos reingresos).
     * Los movimientos anteriores a esta versión no tienen talla: se toma la de su línea si hay una sola.
     *
     * @return Collection<string, array{warehouse_id:int, product_id:int, size:string, quantity:int}>
     */
    public function held(Order $order): Collection
    {
        $rows = InventoryMovement::where('reference_type', self::REFERENCE)
            ->where('reference_id', $order->id)
            ->whereIn('movement_type', ['out', 'in'])
            ->selectRaw("COALESCE(from_warehouse_id, to_warehouse_id) AS wh, product_id, size, SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE -quantity END) AS qty")
            ->groupBy('wh', 'product_id', 'size')
            ->get();
        if ($rows->isEmpty()) {
            return collect();
        }

        $lineSizes = null;
        $held = collect();
        foreach ($rows as $row) {
            $size = $this->size($row->size);
            if ($row->size === null) {
                $lineSizes ??= $order->products()->get(['product_id', 'size'])
                    ->groupBy('product_id')
                    ->map(fn ($lines) => $lines->map(fn ($l) => $this->size($l->size))->unique()->values());
                $sizes = $lineSizes[$row->product_id] ?? collect();
                $size = $sizes->count() === 1 ? $sizes->first() : '';
            }
            $key = $this->key((int) $row->wh, (int) $row->product_id, $size);
            $current = $held[$key]['quantity'] ?? 0;
            $held[$key] = [
                'warehouse_id' => (int) $row->wh,
                'product_id' => (int) $row->product_id,
                'size' => $size,
                'quantity' => $current + (int) $row->qty,
            ];
        }

        return $held->filter(fn ($h) => $h['quantity'] !== 0);
    }

    /** Lo que la orden debería tener fuera: todos sus productos, en el almacén de su agencia. */
    private function target(Order $order, ?string $status, Collection $held): Collection
    {
        $default = $order->resolveStockWarehouseId();
        // Lo entregado ya salió: si después le cambian la agencia, su stock no se mueve de almacén
        $pinned = $status === OrderStatus::ENTREGADO && $held->isNotEmpty();

        $target = collect();
        foreach ($order->products()->get(['product_id', 'size', 'quantity']) as $line) {
            $qty = (int) $line->quantity;
            $warehouseId = $pinned
                ? ($held->firstWhere('product_id', $line->product_id)['warehouse_id'] ?? $held->first()['warehouse_id'])
                : $default;
            if ($qty <= 0 || !$warehouseId) {
                continue;
            }
            $size = $this->size($line->size);
            $key = $this->key($warehouseId, (int) $line->product_id, $size);
            $target[$key] = [
                'warehouse_id' => (int) $warehouseId,
                'product_id' => (int) $line->product_id,
                'size' => $size,
                'quantity' => ($target[$key]['quantity'] ?? 0) + $qty,
            ];
        }

        return $target;
    }

    /** Aplica un movimiento: cambia el total y, si hay talla, su desglose. Puede quedar en negativo para que se vea. */
    private function move(Order $order, array $op, ?string $status): void
    {
        $inv = $this->lockedInventory($op['warehouse_id'], $op['product_id']);
        $sign = $op['type'] === 'out' ? -1 : 1;
        $update = ['quantity' => $inv->quantity + $sign * $op['quantity'], 'updated_at' => now()];
        if ($op['size'] !== '') {
            $sizes = is_array($inv->sizes_stock) ? $inv->sizes_stock : [];
            $sizes[$op['size']] = ($sizes[$op['size']] ?? 0) + $sign * $op['quantity'];
            $update['sizes_stock'] = json_encode($sizes, JSON_UNESCAPED_UNICODE);
        }
        // Sin pasar por el modelo: su evento saving recalcularía el total con las tallas
        DB::table('inventories')->where('id', $inv->id)->update($update);

        InventoryMovement::create([
            'product_id' => $op['product_id'],
            'from_warehouse_id' => $op['type'] === 'out' ? $op['warehouse_id'] : null,
            'to_warehouse_id' => $op['type'] === 'in' ? $op['warehouse_id'] : null,
            'quantity' => $op['quantity'],
            'size' => $op['size'] ?: null,
            'movement_type' => $op['type'],
            'status' => 'completed',
            'reference_type' => self::REFERENCE,
            'reference_id' => $order->id,
            'user_id' => Auth::id(),
            'notes' => ($op['type'] === 'out' ? 'Salida' : 'Reingreso') . " por la orden #{$order->name} ({$status})",
        ]);
    }

    private function lockedInventory(int $warehouseId, int $productId): Inventory
    {
        $inv = Inventory::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first();
        if (!$inv) {
            Inventory::create(['warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 0]);
            $inv = Inventory::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first();
        }

        return $inv;
    }

    private function log(Order $order, array $ops, ?string $status): void
    {
        $products = Product::whereIn('id', array_column($ops, 'product_id'))->pluck('title', 'id');
        $warehouses = DB::table('warehouses')->whereIn('id', array_column($ops, 'warehouse_id'))->pluck('name', 'id');
        $parts = array_map(function ($op) use ($products, $warehouses) {
            $what = "{$op['quantity']} × " . ($products[$op['product_id']] ?? "producto {$op['product_id']}")
                . ($op['size'] !== '' ? " (talla {$op['size']})" : '');

            $where = $warehouses[$op['warehouse_id']] ?? "el almacén {$op['warehouse_id']}";

            return $op['type'] === 'out' ? "salió {$what} de {$where}" : "volvió {$what} a {$where}";
        }, $ops);

        $this->activity($order, 'Stock (' . ($status ?? 'sin estado') . '): ' . implode('; ', $parts) . '.', ['stock_moves' => $ops]);
    }

    private function activity(Order $order, string $description, array $properties = []): void
    {
        try {
            OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => Auth::id(),
                'action' => 'stock_movement',
                'description' => $description,
                'properties' => $properties,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function size(?string $size): string
    {
        return trim((string) $size);
    }

    private function key(int $warehouseId, int $productId, string $size): string
    {
        return "{$warehouseId}|{$productId}|{$size}";
    }
}
