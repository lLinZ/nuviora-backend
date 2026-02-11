<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Exception;

class InventoryService
{
    /**
     * Transfer stock between warehouses
     */
    public function transferBetweenWarehouses(
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $quantity,
        ?int $userId = null,
        ?string $notes = null
    ) {
        return DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $userId, $notes) {
            // Validate warehouses exist and are active
            $fromWarehouse = Warehouse::active()->findOrFail($fromWarehouseId);
            $toWarehouse = Warehouse::active()->findOrFail($toWarehouseId);

            // Get source inventory
            $fromInventory = Inventory::where('warehouse_id', '=', $fromWarehouseId)
                ->where('product_id', '=', $productId)
                ->first();

            if (!$fromInventory || $fromInventory->quantity < $quantity) {
                throw new Exception('Insufficient stock in source warehouse');
            }

            // Decrease from source
            $fromInventory->quantity -= $quantity;
            $fromInventory->save();

            // Increase in destination (create if doesn't exist)
            $toInventory = Inventory::firstOrCreate(
                [
                    'warehouse_id' => $toWarehouseId,
                    'product_id' => $productId,
                ],
                ['quantity' => 0]
            );
            $toInventory->quantity += $quantity;
            $toInventory->save();

            // Record movement
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'quantity' => $quantity,
                'movement_type' => 'transfer',
                'user_id' => $userId,
                'notes' => $notes,
            ]);

            // 📦 Check for orders that now have insufficient stock
            $this->checkAndHandleStockShortage($productId, $fromWarehouseId);
            
            // 📦 Check for orders that now have stock recovered
            $this->checkAndHandleStockRecovery($productId, $toWarehouseId);

            return $movement;
        });
    }

    /**
     * Add stock to a warehouse (incoming)
     */
    public function addStock(
        int $productId,
        int $warehouseId,
        int $quantity,
        ?int $userId = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ) {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $notes, $referenceType, $referenceId) {
            // Validate warehouse exists and is active
            $warehouse = Warehouse::active()->findOrFail($warehouseId);

            // Increase inventory
            $inventory = Inventory::firstOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                ],
                ['quantity' => 0]
            );
            $inventory->quantity += $quantity;
            $inventory->save();

            // Record movement
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'from_warehouse_id' => null,
                'to_warehouse_id' => $warehouseId,
                'quantity' => $quantity,
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
     * Remove stock from a warehouse (outgoing)
     */
    public function removeStock(
        int $productId,
        int $warehouseId,
        int $quantity,
        ?int $userId = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ) {
        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $notes, $referenceType, $referenceId) {
            // Validate warehouse exists and is active
            $warehouse = Warehouse::active()->findOrFail($warehouseId);

            // Get inventory
            $inventory = Inventory::where('warehouse_id', '=', $warehouseId)
                ->where('product_id', '=', $productId)
                ->first();

            if (!$inventory || $inventory->quantity < $quantity) {
                throw new Exception('Insufficient stock in warehouse');
            }

            // Decrease inventory
            $inventory->quantity -= $quantity;
            $inventory->save();

            // Record movement
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'from_warehouse_id' => $warehouseId,
                'to_warehouse_id' => null,
                'quantity' => $quantity,
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
     * Adjust stock in a warehouse
     */
    public function adjustStock(
        int $productId,
        int $warehouseId,
        int $newQuantity,
        ?int $userId = null,
        ?string $notes = null
    ) {
        return DB::transaction(function () use ($productId, $warehouseId, $newQuantity, $userId, $notes) {
            // Validate warehouse exists and is active
            $warehouse = Warehouse::active()->findOrFail($warehouseId);

            // Get or create inventory
            $inventory = Inventory::firstOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                ],
                ['quantity' => 0]
            );

            $oldQuantity = $inventory->quantity;
            $difference = $newQuantity - $oldQuantity;

            // Update inventory
            $inventory->quantity = $newQuantity;
            $inventory->save();

            // Record movement
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'from_warehouse_id' => null,
                'to_warehouse_id' => $warehouseId,
                'quantity' => abs($difference),
                'movement_type' => 'adjustment',
                'user_id' => $userId,
                'notes' => $notes . " (Old: {$oldQuantity}, New: {$newQuantity})",
            ]);

            // 📦 Check for orders that now have insufficient stock (only if quantity decreased)
            if ($difference < 0) {
                $this->checkAndHandleStockShortage($productId, $warehouseId);
            } else if ($difference > 0) {
                // 📦 Check for orders that now have stock recovered
                $this->checkAndHandleStockRecovery($productId, $warehouseId);
            }

            return $movement;
        });
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

        // Excluded statuses (don't de-assign if already finished)
        $excludedStatuses = ['Entregado', 'En ruta', 'Cancelado', 'Rechazado', 'Sin Stock', 'Novedades', 'Novedad Solucionada'];

        // Find orders assigned to this agency
        $orders = \App\Models\Order::where('agency_id', '=', $warehouse->user_id)
            ->whereHas('status', function($q) use ($excludedStatuses) {
                $q->whereNotIn('description', $excludedStatuses);
            })
            ->whereHas('products', function($q) use ($productId) {
                $q->where('product_id', $productId);
            })
            ->get();

        foreach ($orders as $order) {
            // Check if THIS specific order now has insufficient stock for ANY of its products
            // Using the helper we added to OrderController (or similar logic)
            // But here we know at least $productId stock just changed.
            
            $inv = Inventory::where('warehouse_id', '=', $warehouseId)
                ->where('product_id', '=', $productId)
                ->first();
            
            $available = $inv ? $inv->quantity : 0;
            
            // Required quantity for this product in this order
            $required = $order->products()->where('product_id', $productId)->sum('quantity');

            if ($available < $required) {
                $oldAgentId = $order->agent_id;
                
                // Update order
                $order->status_id = $sinStockStatus->id;
                $order->agent_id = null; // De-assign seller
                $order->save();

                // Log the activity
                \App\Models\OrderActivityLog::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id() ?? 1, // System or current user
                    'action' => 'status_changed',
                    'description' => "Orden movida a 'Sin Stock' y vendedora removida por falta de existencias en bodega.",
                    'properties' => [
                        'old_status' => $order->getOriginal('status_id'),
                        'new_status' => $sinStockStatus->id,
                        'old_agent_id' => $oldAgentId,
                        'new_agent_id' => null,
                        'reason' => 'stock_shortage',
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId
                    ]
                ]);

                // Also add an update for the history timeline
                \App\Models\OrderUpdate::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id() ?? \App\Models\User::whereHas('role', function($q){ $q->where('description', '=', 'Admin'); })->first()?->id ?? 1,
                    'message' => "🚨 AUTOMÁTICO: La orden pasó a 'Sin Stock' y se removió la vendedora asignada debido a falta de existencias de un producto en la bodega de la agencia."
                ]);

                // 📡 Broadcast via WebSocket for real-time Kanban update
                $order->load(['status', 'client', 'agent', 'agency', 'deliverer']);
                event(new \App\Events\OrderUpdated($order));
            }
        }
    }

    /**
     * Finds orders in "Sin Stock" that can now be fulfilled because stock was added
     * to a warehouse, and tries to re-assign them.
     */
    private function checkAndHandleStockRecovery(int $productId, int $warehouseId)
    {
        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) return;

        // Find relevant statuses
        $sinStockStatus = \App\Models\Status::where('description', 'Sin Stock')->first();
        $assignedStatus = \App\Models\Status::where('description', 'Asignado a Vendedor')->first();
        $nuevoStatus = \App\Models\Status::where('description', 'Nuevo')->first();
        
        if (!$sinStockStatus) return;

        // Find orders in "Sin Stock" that contain this product
        $query = \App\Models\Order::where('status_id', $sinStockStatus->id)
            ->whereHas('products', function($q) use ($productId) {
                $q->where('product_id', $productId);
            });

        // If it's an agency warehouse, only check orders for that agency
        if ($warehouse->user_id) {
            $query->where('agency_id', $warehouse->user_id);
        }

        $orders = $query->get();
        if ($orders->isEmpty()) return;

        $assignService = app(\App\Services\Assignment\AssignOrderService::class);

        foreach ($orders as $order) {
            // Reload order with products to ensure fresh check
            if ($order->hasStock()) {
                // Try to assign it automatically
                $agent = $assignService->assignOne($order);
                
                if ($agent && $assignedStatus) {
                    // Update status to "Asignado a Vendedor" since assignOne only sets agent_id
                    $order->status_id = $assignedStatus->id;
                    $order->save();

                    // Log activity
                    \App\Models\OrderActivityLog::create([
                        'order_id' => $order->id,
                        'user_id' => auth()->id() ?? 1,
                        'action' => 'status_changed',
                        'description' => "Stock recuperado. Orden asignada automáticamente a {$agent->names}.",
                        'properties' => [
                            'old_status' => $sinStockStatus->id,
                            'new_status' => $assignedStatus->id,
                            'agent_id' => $agent->id
                        ]
                    ]);
                } else if ($nuevoStatus) {
                    // Back to "Nuevo" if no agent could be assigned (outside business hours or no roster)
                    $order->status_id = $nuevoStatus->id;
                    $order->save();

                    // Log activity
                    \App\Models\OrderActivityLog::create([
                        'order_id' => $order->id,
                        'user_id' => auth()->id() ?? 1,
                        'action' => 'status_changed',
                        'description' => "Stock recuperado. Orden movida a 'Nuevo' (Pendiente de asignación).",
                        'properties' => [
                            'old_status' => $sinStockStatus->id,
                            'new_status' => $nuevoStatus->id
                        ]
                    ]);
                }
            }
        }
    }
}
