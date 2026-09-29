<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\VariantStock;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Entradas, salidas, ajustes y traslados de stock. Tarea 4: cada operación puede traer su desglose por
 * variante ([variant_id => cantidad], o el formato viejo por nombre de talla). Lo que no se reparte se
 * toma del stock "sin variante" (el total menos lo repartido), y una salida no puede sacar de una talla más
 * de lo que tiene. Cada variante deja su propio movimiento, para verla en el historial.
 */
class InventoryService
{
    public function __construct(private ?VariantStock $variants = null)
    {
        $this->variants ??= app(VariantStock::class);
    }

    /**
     * Transfer stock between warehouses
     */
    public function transferBetweenWarehouses(
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $quantity,
        ?int $userId = null,
        ?string $notes = null,
        $variants = null
    ) {
        return DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $userId, $notes, $variants) {
            // Validate warehouses exist and are active
            Warehouse::active()->findOrFail($fromWarehouseId);
            Warehouse::active()->findOrFail($toWarehouseId);

            $split = $this->split($productId, $variants, $quantity);

            $fromInventory = $this->lockedInventory($fromWarehouseId, $productId, false);
            if (!$fromInventory || $fromInventory->quantity < $quantity) {
                throw new Exception('Stock insuficiente en el almacén de origen');
            }
            $this->takeFromVariants($fromInventory, $split, $quantity);
            foreach ($split as $variantId => $qty) {
                $this->variants->add($toWarehouseId, $productId, $variantId, $qty);
            }

            DB::table('inventories')->where('id', $fromInventory->id)->update(['quantity' => $fromInventory->quantity - $quantity, 'updated_at' => now()]);
            $toInventory = $this->lockedInventory($toWarehouseId, $productId);
            DB::table('inventories')->where('id', $toInventory->id)->update(['quantity' => $toInventory->quantity + $quantity, 'updated_at' => now()]);

            // Traslado inmediato (sin confirmación en dos pasos)
            $movement = $this->record($productId, $quantity, $split, [
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id'   => $toWarehouseId,
                'movement_type'     => 'transfer',
                'status'            => 'completed',
                'user_id'           => $userId,
                'notes'             => $notes,
            ]);

            // Check for orders affected by the stock change at source
            $this->checkAndHandleStockShortage($productId, $fromWarehouseId);
            // Check for orders that can now be fulfilled at destination
            $this->checkAndHandleStockRecovery($productId, $toWarehouseId);

            return $movement;
        });
    }

    /**
     * Confirm a pending transfer and increase stock in destination
     */
    public function confirmTransfer(int $movementId, ?int $userId = null)
    {
        return DB::transaction(function () use ($movementId, $userId) {
            $movement = InventoryMovement::where('status', 'pending')
                ->where('movement_type', 'transfer')
                ->findOrFail($movementId);

            // Increase in destination (create if doesn't exist)
            $toInventory = $this->lockedInventory($movement->to_warehouse_id, $movement->product_id);
            DB::table('inventories')->where('id', $toInventory->id)->update(['quantity' => $toInventory->quantity + $movement->quantity, 'updated_at' => now()]);
            if ($movement->variant_id) {
                $this->variants->add($movement->to_warehouse_id, $movement->product_id, $movement->variant_id, $movement->quantity);
            }

            // Mark as completed
            $movement->status = 'completed';
            if ($userId) $movement->notes .= " (Confirmado por ID: {$userId})";
            $movement->save();

            // 📦 Check for orders that now have stock recovered at destination
            $this->checkAndHandleStockRecovery($movement->product_id, $movement->to_warehouse_id);

            return $movement;
        });
    }

    /**
     * Reject a pending transfer and return stock to source
     */
    public function rejectTransfer(int $movementId, ?int $userId = null)
    {
        return DB::transaction(function () use ($movementId, $userId) {
            $movement = InventoryMovement::where('status', 'pending')
                ->where('movement_type', 'transfer')
                ->findOrFail($movementId);

            // Return stock to source
            $fromInventory = $this->lockedInventory($movement->from_warehouse_id, $movement->product_id, false);
            if ($fromInventory) {
                DB::table('inventories')->where('id', $fromInventory->id)->update(['quantity' => $fromInventory->quantity + $movement->quantity, 'updated_at' => now()]);
                if ($movement->variant_id) {
                    $this->variants->add($movement->from_warehouse_id, $movement->product_id, $movement->variant_id, $movement->quantity);
                }
            }

            // Mark as cancelled
            $movement->status = 'cancelled';
            if ($userId) $movement->notes .= " (Rechazado por ID: {$userId})";
            $movement->save();

            // 📦 Check for orders that now have stock recovered at source
            $this->checkAndHandleStockRecovery($movement->product_id, $movement->from_warehouse_id);

            return $movement;
        });
    }

    /**
     * Add stock to a warehouse (incoming). Lo que no se reparte por variante queda "sin variante".
     */
    public function addStock(
        int $productId,
        int $warehouseId,
        int $quantity,
        ?int $userId = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        $variants = null
    ) {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $notes, $referenceType, $referenceId, $variants) {
            // Validate warehouse exists and is active
            Warehouse::active()->findOrFail($warehouseId);

            $split = $this->split($productId, $variants, $quantity);

            $inventory = $this->lockedInventory($warehouseId, $productId);
            DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => $inventory->quantity + $quantity, 'updated_at' => now()]);
            foreach ($split as $variantId => $qty) {
                $this->variants->add($warehouseId, $productId, $variantId, $qty);
            }

            $movement = $this->record($productId, $quantity, $split, [
                'from_warehouse_id' => null,
                'to_warehouse_id' => $warehouseId,
                'movement_type' => 'in',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $userId,
                'notes' => $notes,
            ]);

            // 📦 Check for orders that now have stock recovered
            $this->checkAndHandleStockRecovery($productId, $warehouseId);

            return $movement;
        });
    }

    /**
     * Remove stock from a warehouse (outgoing). Sin desglose, sale del stock "sin variante".
     */
    public function removeStock(
        int $productId,
        int $warehouseId,
        int $quantity,
        ?int $userId = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        $variants = null
    ) {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $notes, $referenceType, $referenceId, $variants) {
            // Validate warehouse exists and is active
            Warehouse::active()->findOrFail($warehouseId);

            $split = $this->split($productId, $variants, $quantity);

            $inventory = $this->lockedInventory($warehouseId, $productId, false);
            if (!$inventory || $inventory->quantity < $quantity) {
                throw new Exception('Stock insuficiente en el almacén');
            }
            $this->takeFromVariants($inventory, $split, $quantity);
            DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => $inventory->quantity - $quantity, 'updated_at' => now()]);

            $movement = $this->record($productId, $quantity, $split, [
                'from_warehouse_id' => $warehouseId,
                'to_warehouse_id' => null,
                'movement_type' => 'out',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $userId,
                'notes' => $notes,
            ]);

            // 📦 Check for orders that now have insufficient stock
            $this->checkAndHandleStockShortage($productId, $warehouseId);

            return $movement;
        });
    }

    /**
     * Adjust stock in a warehouse. Fija el total y, si vienen, las cantidades de esas variantes (las demás
     * no cambian). Lo repartido por variante no puede superar el total.
     */
    public function adjustStock(
        int $productId,
        int $warehouseId,
        int $newQuantity,
        ?int $userId = null,
        ?string $notes = null,
        $variants = null
    ) {
        return DB::transaction(function () use ($productId, $warehouseId, $newQuantity, $userId, $notes, $variants) {
            // Validate warehouse exists and is active
            Warehouse::active()->findOrFail($warehouseId);

            $split = $this->variants->parse($productId, $variants, true);

            $inventory = $this->lockedInventory($warehouseId, $productId);
            $oldQuantity = (int) $inventory->quantity;
            $difference = $newQuantity - $oldQuantity;

            $changes = [];
            foreach ($split as $variantId => $qty) {
                $before = $this->variants->set($warehouseId, $productId, $variantId, $qty);
                if ($before !== $qty) {
                    $changes[$variantId] = [$before, $qty];
                }
            }
            $assigned = $this->variants->assigned($warehouseId, $productId);
            if ($assigned > $newQuantity) {
                throw new Exception("Lo repartido por variante ({$assigned}) supera el total ({$newQuantity}). Ajusta también las variantes.");
            }

            DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => $newQuantity, 'updated_at' => now()]);

            $detail = '';
            if ($changes) {
                $titles = ProductVariant::whereIn('id', array_keys($changes))->pluck('title', 'id');
                $detail = ' · Variantes: ' . implode(', ', array_map(
                    fn ($id, $c) => ($titles[$id] ?? "#{$id}") . " {$c[0]}→{$c[1]}",
                    array_keys($changes),
                    $changes
                ));
            }

            // Record movement
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'from_warehouse_id' => null,
                'to_warehouse_id' => $warehouseId,
                'quantity' => abs($difference),
                'movement_type' => 'adjustment',
                'user_id' => $userId,
                'notes' => $notes . " (Old: {$oldQuantity}, New: {$newQuantity})" . $detail,
            ]);

            // 📦 Si bajó (el total o una talla), hay órdenes que pueden quedarse sin stock; si subió, recuperarlo
            $wentDown = $difference < 0 || collect($changes)->contains(fn ($c) => $c[1] < $c[0]);
            $wentUp = $difference > 0 || collect($changes)->contains(fn ($c) => $c[1] > $c[0]);
            if ($wentDown) {
                $this->checkAndHandleStockShortage($productId, $warehouseId);
            }
            if ($wentUp) {
                $this->checkAndHandleStockRecovery($productId, $warehouseId);
            }

            return $movement;
        });
    }

    /** Desglose por variante de una operación de $quantity unidades: no puede sumar más que eso. */
    private function split(int $productId, $variants, int $quantity): array
    {
        $split = $this->variants->parse($productId, $variants);
        $sum = array_sum($split);
        if ($sum > $quantity) {
            throw new Exception("La suma por variante ({$sum}) supera la cantidad ({$quantity}).");
        }

        return $split;
    }

    /**
     * Saca de cada variante lo suyo, y el resto del stock "sin variante". Si una talla no alcanza, o lo que
     * no se repartió no está sin variante, no sale nada.
     */
    private function takeFromVariants(Inventory $inventory, array $split, int $quantity): void
    {
        $rows = $this->variants->rows($inventory->warehouse_id, $inventory->product_id);
        $rest = $quantity - array_sum($split);
        if ($rest > 0) {
            $unassigned = (int) $inventory->quantity - (int) $rows->sum('quantity');
            if ($unassigned < $rest) {
                throw new Exception('Indica de qué variante sale: sin variante hay ' . max(0, $unassigned) . '.');
            }
        }
        foreach ($split as $variantId => $qty) {
            $row = $this->variants->locked($inventory->warehouse_id, $inventory->product_id, $variantId);
            if ($row->quantity < $qty) {
                $title = ProductVariant::whereKey($variantId)->value('title');
                throw new Exception("Stock insuficiente de {$title}: hay {$row->quantity}.");
            }
            $this->variants->add($inventory->warehouse_id, $inventory->product_id, $variantId, -$qty);
        }
    }

    /** Un movimiento por variante y uno por lo que va sin variante. Devuelve el primero. */
    private function record(int $productId, int $quantity, array $split, array $fields): InventoryMovement
    {
        $titles = $split ? ProductVariant::whereIn('id', array_keys($split))->pluck('title', 'id') : collect();
        $movements = [];
        foreach ($split as $variantId => $qty) {
            $movements[] = InventoryMovement::create($fields + [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'size' => $titles[$variantId] ?? null,
                'quantity' => $qty,
            ]);
        }
        $rest = $quantity - array_sum($split);
        if ($rest > 0 || !$movements) {
            $movements[] = InventoryMovement::create($fields + ['product_id' => $productId, 'quantity' => $rest]);
        }

        return $movements[0];
    }

    /** La fila del producto en el almacén, bloqueada. La crea en 0 si no existe (salvo $create = false). */
    private function lockedInventory(int $warehouseId, int $productId, bool $create = true): ?Inventory
    {
        $find = fn () => Inventory::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first();
        $inventory = $find();
        if (!$inventory && $create) {
            Inventory::firstOrCreate(['warehouse_id' => $warehouseId, 'product_id' => $productId], ['quantity' => 0]);
            $inventory = $find();
        }

        return $inventory;
    }

    /**
     * Get product stock across all warehouses or specific warehouse
     */
    public function getProductStock(int $productId, ?int $warehouseId = null)
    {
        $query = Inventory::where('product_id', '=', $productId);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
            $inventory = $query->first();
            return $inventory ? $inventory->quantity : 0;
        }

        // Return stock grouped by warehouse
        return $query->with('warehouse')->get()->map(function ($inventory) {
            return [
                'warehouse_id' => $inventory->warehouse_id,
                'warehouse_name' => $inventory->warehouse->name,
                'warehouse_code' => $inventory->warehouse->code,
                'quantity' => $inventory->quantity,
            ];
        });
    }

    /**
     * Get total stock for a product across all warehouses
     */
    public function getTotalProductStock(int $productId)
    {
        return Inventory::where('product_id', '=', $productId)->sum('quantity');
    }

    /**
     * Finds orders assigned to a warehouse (via agency) that now have insufficient stock,
     * changes their status to "Sin Stock", and de-assigns the agent.
     */
    private function checkAndHandleStockShortage(int $productId, int $warehouseId)
    {
        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse || !$warehouse->user_id) return;

        // Find the "Sin Stock" status ID
        $sinStockStatus = \App\Models\Status::where('description', '=', 'Sin Stock')->first();
        if (!$sinStockStatus) return;

        // Excluded statuses (don't de-assign if already finished or if they hold reserved stock)
        $excludedStatuses = ['Entregado', 'En ruta', 'Cancelado', 'Rechazado', 'Sin Stock', 'Novedades', 'Novedad Solucionada', 'Asignar a agencia'];

        // Find orders assigned to this agency that are NOT in a terminal status
        $orders = \App\Models\Order::where('agency_id', '=', $warehouse->user_id)
            ->whereHas('status', function($q) use ($excludedStatuses) {
                $q->whereNotIn('description', $excludedStatuses);
            })
            ->whereHas('products', function($q) use ($productId) {
                $q->where('product_id', $productId);
            })
            ->get();

        foreach ($orders as $order) {
            /** @var \App\Models\Order $order */
            // Utilizamos hasStock() que internamente ya sabe si la orden
            // tiene su stock reservado y descontado previamente.
            if (!$order->hasStock()) {
                // Varias agencias por ciudad (tarea 3c): si otra de la ciudad tiene stock, la orden pasa a esa
                if (app(\App\Services\Agencies\AgencyRouter::class)->provisional($order)) {
                    continue;
                }
                $oldStatusId = $order->status_id; // 💾 Guardar status anterior

                $order->previous_status_id = $oldStatusId; // 💾 Persistir para restaurar después
                $order->status_id = $sinStockStatus->id;
                // ✅ NO se desasigna agent_id: la vendedora permanece asignada
                // para que cuando el stock se recupere, la orden vuelva a su
                // estado anterior con la misma vendedora sin necesidad de re-asignar.
                $order->save();

                \App\Models\OrderActivityLog::create([
                    'order_id' => $order->id,
                    'user_id'  => auth()->id() ?? 1,
                    'action'   => 'status_changed',
                    'description' => "Orden movida a 'Sin Stock' por falta de existencias en bodega. Vendedora mantiene la asignación.",
                    'properties' => [
                        'old_status'   => $oldStatusId,
                        'new_status'   => $sinStockStatus->id,
                        'agent_id'     => $order->agent_id,
                        'reason'       => 'stock_shortage',
                        'product_id'   => $productId,
                        'warehouse_id' => $warehouseId
                    ]
                ]);

                \App\Models\OrderUpdate::create([
                    'order_id' => $order->id,
                    'user_id'  => auth()->id() ?? \App\Models\User::whereHas('role', function($q){ $q->where('description', '=', 'Admin'); })->first()?->id ?? 1,
                    'message'  => "🚨 AUTOMÁTICO: La orden pasó a 'Sin Stock' por falta de existencias en bodega. La vendedora asignada se mantiene."
                ]);

                $order->load(['status', 'client', 'agent', 'agency', 'deliverer']);
                event(new \App\Events\OrderUpdated($order));
            }
        }
    }

    /**
     * Finds orders in "Sin Stock" that can now be fulfilled because stock was added
     * to a warehouse, and tries to re-assign them.
     * 
     * Priority for restored status:
     *   1. previous_status_id saved when order was moved to Sin Stock  → restore it
     *   2. If no previous_status_id, attempt auto-assign → "Asignado a Vendedor"
     *   3. If outside business hours, fall back to "Nuevo"
     */
    private function checkAndHandleStockRecovery(int $productId, int $warehouseId)
    {
        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) return;

        $sinStockStatus = \App\Models\Status::where('description', 'Sin Stock')->first();
        $assignedStatus = \App\Models\Status::where('description', 'Asignado a Vendedor')->first();
        $nuevoStatus    = \App\Models\Status::where('description', 'Nuevo')->first();

        // 🛑 Terminal statuses — NEVER touch these orders automatically
        $terminalStatuses = ['Entregado', 'Cancelado', 'Rechazado', 'En ruta', 'Asignar a agencia', 'Novedades', 'Novedad Solucionada'];
        $terminalIds = \App\Models\Status::whereIn('description', $terminalStatuses)->pluck('id')->toArray();

        if (!$sinStockStatus) return;

        $query = \App\Models\Order::where('status_id', $sinStockStatus->id)
            ->whereNotIn('status_id', $terminalIds) // 🛡️ Extra guard
            ->whereHas('products', function($q) use ($productId) {
                $q->where('product_id', $productId);
            });

        if ($warehouse->user_id) {
            $query->where('agency_id', $warehouse->user_id);
        }

        $orders = $query->get();
        if ($orders->isEmpty()) return;

        foreach ($orders as $order) {
            $this->recoverFromSinStock($order);
        }
    }

    /**
     * Si la orden está en "Sin Stock" y ya le alcanza, vuelve a su estado anterior (o se reparte, o pasa a
     * "Nuevo"). Lo usa la entrada de stock y también el cambio de talla de una línea (tarea 4).
     */
    public function recoverFromSinStock(\App\Models\Order $order): bool
    {
        $sinStockStatus = \App\Models\Status::where('description', 'Sin Stock')->first();
        $assignedStatus = \App\Models\Status::where('description', 'Asignado a Vendedor')->first();
        $nuevoStatus    = \App\Models\Status::where('description', 'Nuevo')->first();
        if (!$sinStockStatus) return false;

        // 🛑 Terminal statuses — NEVER touch these orders automatically
        $terminalStatuses = ['Entregado', 'Cancelado', 'Rechazado', 'En ruta', 'Asignar a agencia', 'Novedades', 'Novedad Solucionada'];

        /** @var \App\Models\Order $order */
        // 🛑 HARD GUARD: Refresh + verify still Sin Stock, not a terminal status
        $order->refresh();
        $currentStatusDesc = $order->status?->description;
        if ($currentStatusDesc !== 'Sin Stock' || in_array($currentStatusDesc, $terminalStatuses)) {
            \Log::info("InventoryService: Skipping order #{$order->name} — status is '{$currentStatusDesc}', not Sin Stock.");
            return false;
        }

        if (!$order->hasStock()) return false; // Still missing stock, skip

        // ⭐ CASO 1: Tiene status anterior guardado → restaurarlo directamente
        if ($order->previous_status_id) {
            $restoredStatus = \App\Models\Status::find($order->previous_status_id);

            // Safety: don't restore to a terminal status (edge case)
            if ($restoredStatus && !in_array($restoredStatus->description, $terminalStatuses)) {
                $order->status_id          = $restoredStatus->id;
                $order->previous_status_id = null; // Limpiar después de restaurar
                // 🔥 FIX: Solo liberar a la especialista si la orden originalmente venía del pool general (Nuevo)
                if ($restoredStatus->description === 'Nuevo') {
                    $order->agent_id = null;
                }
                $order->save();

                \App\Models\OrderActivityLog::create([
                    'order_id'    => $order->id,
                    'user_id'     => auth()->id() ?? 1,
                    'action'      => 'status_changed',
                    'description' => "Stock recuperado. Orden restaurada a su status anterior: '{$restoredStatus->description}'.",
                    'properties'  => [
                        'old_status' => $sinStockStatus->id,
                        'new_status' => $restoredStatus->id,
                        'restored'   => true,
                    ]
                ]);

                \App\Models\OrderUpdate::create([
                    'order_id' => $order->id,
                    'user_id'  => auth()->id() ?? 1,
                    'message'  => "✅ AUTOMÁTICO: Stock recuperado. La orden volvió a su status anterior: '{$restoredStatus->description}'."
                ]);

                $order->load(['status', 'client', 'agent', 'agency', 'deliverer']);
                event(new \App\Events\OrderUpdated($order));
                return true;
            }
        }

        // ⭐ CASO 2: No hay status anterior guardado → intentar auto-asignar
        $order->agent_id = null; // 🔥 FIX: Liberar a la especialista antes de auto-asignar
        $order->save();

        $agent = app(\App\Services\Assignment\AssignOrderService::class)->assignOne($order);

        if ($agent && $assignedStatus) {
            $order->status_id          = $assignedStatus->id;
            $order->previous_status_id = null;
            $order->save();

            \App\Models\OrderActivityLog::create([
                'order_id'    => $order->id,
                'user_id'     => auth()->id() ?? 1,
                'action'      => 'status_changed',
                'description' => "Stock recuperado. Orden asignada automáticamente a {$agent->names}.",
                'properties'  => [
                    'old_status' => $sinStockStatus->id,
                    'new_status' => $assignedStatus->id,
                    'agent_id'   => $agent->id,
                ]
            ]);
        } elseif ($nuevoStatus) {
            // ⭐ CASO 3: Sin agente disponible → Nuevo
            $order->status_id          = $nuevoStatus->id;
            $order->previous_status_id = null;
            $order->agent_id           = null; // 🔥 FIX: Liberar a la especialista
            $order->save();

            \App\Models\OrderActivityLog::create([
                'order_id'    => $order->id,
                'user_id'     => auth()->id() ?? 1,
                'action'      => 'status_changed',
                'description' => "Stock recuperado. Orden movida a 'Nuevo' (sin agente disponible).",
                'properties'  => [
                    'old_status' => $sinStockStatus->id,
                    'new_status' => $nuevoStatus->id,
                ]
            ]);
        }

        $order->load(['status', 'client', 'agent', 'agency', 'deliverer']);
        event(new \App\Events\OrderUpdated($order));
        return true;
    }
}
