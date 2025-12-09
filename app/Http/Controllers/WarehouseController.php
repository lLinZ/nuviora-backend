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
        $query = Warehouse::with('warehouseType');

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
            'data' => $warehouse->load('warehouseType'),
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
            'data' => $warehouse->load('warehouseType'),
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
    public function inventory($id)
    {
        $warehouse = Warehouse::findOrFail($id);

        $inventory = $warehouse->inventories()
            ->with('product')
            ->get()
            ->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? $item->product->title,
                    'product_sku' => $item->product->sku,
                    'quantity' => $item->quantity,
                ];
            });

        return response()->json([
            'success' => true,
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
            ],
            'inventory' => $inventory,
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
