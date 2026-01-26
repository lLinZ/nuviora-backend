<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\WarehouseType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WarehouseController extends Controller
{
    /**
     * Display a listing of warehouses
     */
    public function index(Request $request)
    {
        $query = Warehouse::with(['warehouseType', 'user'])
            ->withCount('inventories as total_products_unique')
            ->withSum('inventories as total_items_stock', 'quantity');

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Filter by warehouse type
        if ($request->has('type_id')) {
            $query->where('warehouse_type_id', $request->type_id);
        }

        // Filter by warehouse type code
        if ($request->has('type_code')) {
            $query->whereHas('warehouseType', function ($q) use ($request) {
                $q->where('code', $request->type_code);
            });
        }
        
        // Search by name or code or user name
        if ($request->filled('q')) {
            $term = $request->q;
            $query->where(function($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('code', 'like', "%{$term}%")
                  ->orWhereHas('user', function($qu) use ($term){
                      $qu->where('names', 'like', "%{$term}%")
                         ->orWhere('surnames', 'like', "%{$term}%");
                  });
            });
        }

        $warehouses = $query->get();

        return response()->json([
            'success' => true,
            'data' => $warehouses,
        ]);
    }

    /**
     * Store a newly created warehouse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'warehouse_type_id' => 'required|exists:warehouse_types,id',
            'user_id' => 'nullable|exists:users,id',
            'code' => 'required|string|max:50|unique:warehouses,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'location' => 'nullable|string',
            'is_active' => 'boolean',
            'is_main' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $warehouse = Warehouse::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Warehouse created successfully',
            'data' => $warehouse->load(['warehouseType', 'user']),
        ], 201);
    }

    /**
     * Display the specified warehouse
     */
    public function show($id)
    {
        $warehouse = Warehouse::with('warehouseType')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $warehouse,
        ]);
    }

    /**
     * Update the specified warehouse
     */
    public function update(Request $request, $id)
    {
        $warehouse = Warehouse::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'warehouse_type_id' => 'sometimes|exists:warehouse_types,id',
            'user_id' => 'nullable|exists:users,id',
            'code' => 'sometimes|string|max:50|unique:warehouses,code,' . $id,
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'location' => 'nullable|string',
            'is_active' => 'boolean',
            'is_main' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $warehouse->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Warehouse updated successfully',
            'data' => $warehouse->load(['warehouseType', 'user']),
        ]);
    }

    /**
     * Remove the specified warehouse
     */
    public function destroy($id)
    {
        $warehouse = Warehouse::findOrFail($id);

        // Prevent deletion of main warehouse
        if ($warehouse->is_main) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the main warehouse',
            ], 403);
        }

        // Check if warehouse has inventory
        if ($warehouse->inventories()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete warehouse with existing inventory',
            ], 403);
        }

        $warehouse->delete();

        return response()->json([
            'success' => true,
            'message' => 'Warehouse deleted successfully',
        ]);
    }

    /**
     * Get inventory for a specific warehouse
     */
    public function inventory(Request $request, $id)
    {
        $warehouse = Warehouse::findOrFail($id);

        $query = $warehouse->inventories()->with('product');

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $inventory = $query->get();
        
        $currentStock = 0;
        if ($request->has('product_id')) {
            $currentStock = $inventory->first()?->quantity ?? 0;
        }

        return response()->json([
            'success' => true,
            'warehouse' => $warehouse,
            'inventory' => $inventory,
            'current_stock' => $currentStock
        ]);
    }
    /**
     * Get all warehouse types
     */
    public function getTypes()
    {
        $types = WarehouseType::all();
        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }
}
