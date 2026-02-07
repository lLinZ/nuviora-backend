<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class InventoryMovementController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Helper to validate warehouse ownership
     * @param int $warehouseId
     * @return bool|string True if allowed, error message string if denied
     */
    private function validateOwnership($warehouseId)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user) return 'Usuario no autenticado';

        // Superusers can access anything
        if (in_array($user->role?->description, ['Admin', 'Gerente', 'Master'])) {
            return true;
        }

        // Agencies can only access their own warehouses
        if ($user->role?->description === 'Agencia') {
            $warehouse = \App\Models\Warehouse::find($warehouseId);
            if (!$warehouse) return 'Almacén no encontrado';
            
            if ($warehouse->user_id !== $user->id) {
                return '⛔ ACCESO DENEGADO: No tienes permiso para modificar este almacén.';
            }
            return true;
        }

        return '⛔ Rol no autorizado para gestión de inventario.';
    }

    /**
     * Display a listing of inventory movements
     */
    public function index(Request $request)
    {
        $query = InventoryMovement::with(['product', 'fromWarehouse', 'toWarehouse', 'user']);

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by warehouse (from or to)
        if ($request->has('warehouse_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('from_warehouse_id', $request->warehouse_id)
                  ->orWhere('to_warehouse_id', $request->warehouse_id);
            });
        }

        // 🔒 RESTRICT AGENCY TO OWN WAREHOUSE MOVEMENTS
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user->role?->description === 'Agencia') {
            $query->where(function($q) use ($user) {
                $q->whereHas('fromWarehouse', function($qw) use ($user) {
                    $qw->where('user_id', $user->id);
                })->orWhereHas('toWarehouse', function($qw) use ($user) {
                    $qw->where('user_id', $user->id);
                });
            });
        }

        // Filter by movement type
        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Order by most recent
        $query->orderBy('created_at', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 15);
        $movements = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }

    /**
     * Display a specific movement
     */
    public function show($id)
    {
        $movement = InventoryMovement::with(['product', 'fromWarehouse', 'toWarehouse', 'user'])
            ->findOrFail($id);

        // 🔒 CHECK ACCESS
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user->role?->description === 'Agencia') {
            $canView = false;
            if ($movement->fromWarehouse && $movement->fromWarehouse->user_id === $user->id) $canView = true;
            if ($movement->toWarehouse && $movement->toWarehouse->user_id === $user->id) $canView = true;
            
            if (!$canView) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $movement,
        ]);
    }

    /**
     * Transfer stock between warehouses
     */
    public function transfer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $checkFrom = $this->validateOwnership($request->from_warehouse_id);
        if ($checkFrom !== true) return response()->json(['success' => false, 'message' => $checkFrom], 403);

        // Si es Agencia, NO permitimos mover stock HACIA almacenes ajenos (a menos que sea devolución al central, lógica pendiente)
        // Por ahora lo dejamos simple: validar origen.
        
        try {
            $movement = $this->inventoryService->transferBetweenWarehouses(
                $request->product_id,
                $request->from_warehouse_id,
                $request->to_warehouse_id,
                $request->quantity,
                auth()->id(),
                $request->notes
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock transferred successfully',
                'data' => $movement->load(['product', 'fromWarehouse', 'toWarehouse']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Add stock to warehouse (incoming)
     */
    public function stockIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'to_warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $checkTo = $this->validateOwnership($request->to_warehouse_id);
        if ($checkTo !== true) return response()->json(['success' => false, 'message' => $checkTo], 403);

        try {
            $movement = $this->inventoryService->addStock(
                $request->product_id,
                $request->to_warehouse_id,
                $request->quantity,
                auth()->id(),
                $request->notes,
                $request->reference_type,
                $request->reference_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock added successfully',
                'data' => $movement->load(['product', 'toWarehouse']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Remove stock from warehouse (outgoing)
     */
    public function stockOut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $checkFrom = $this->validateOwnership($request->from_warehouse_id);
        if ($checkFrom !== true) return response()->json(['success' => false, 'message' => $checkFrom], 403);

        try {
            $movement = $this->inventoryService->removeStock(
                $request->product_id,
                $request->from_warehouse_id,
                $request->quantity,
                auth()->id(),
                $request->notes,
                $request->reference_type,
                $request->reference_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock removed successfully',
                'data' => $movement->load(['product', 'fromWarehouse']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Adjust stock in warehouse
     */
    public function adjust(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'new_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $check = $this->validateOwnership($request->warehouse_id);
        if ($check !== true) return response()->json(['success' => false, 'message' => $check], 403);

        try {
            $movement = $this->inventoryService->adjustStock(
                $request->product_id,
                $request->warehouse_id,
                $request->new_quantity,
                auth()->id(),
                $request->notes
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'data' => $movement->load(['product', 'toWarehouse']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
