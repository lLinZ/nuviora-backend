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
            $fromInventory = Inventory::where('warehouse_id', $fromWarehouseId)
                ->where('product_id', $productId)
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
            $inventory = Inventory::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
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

            return $movement;
        });
    }

    /**
     * Get product stock across all warehouses or specific warehouse
     */
    public function getProductStock(int $productId, ?int $warehouseId = null)
    {
        $query = Inventory::where('product_id', $productId);

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
        return Inventory::where('product_id', $productId)->sum('quantity');
    }
}
