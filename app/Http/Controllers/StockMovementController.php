<?php

// app/Http/Controllers/StockMovementController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    // GET /products/{product}/movements
    public function index(Request $request)
    {
        $q = StockMovement::with(['product:id,sku,title,name', 'user:id,names,surnames,email'])->orderByDesc('id');
        if ($sku = $request->get('sku')) {
            $q->whereHas('product', fn($w) => $w->where('sku', $sku));
        }
        if ($from = $request->get('from')) $q->whereDate('created_at', '>=', $from);
        if ($to = $request->get('to')) $q->whereDate('created_at', '<=', $to);

        return response()->json(['status' => true, 'data' => $q->paginate(50)]);
    }
    /**
     * POST /stock/movements. Entradas y salidas manuales, ahora en el sistema de almacenes
     * (InventoryService: inventories + inventory_movements), con tallas si vienen. Antes tocaba las tallas
     * del inventario pero dejaba el movimiento en stock_movements, fuera del historial (tarea 3, punto 7).
     * El stock de repartidores se mueve desde su almacén (DelivererStockController).
     */
    public function store(Request $request, InventoryService $inventory)
    {
        $role = Auth::user()->role?->description;

        $data = $request->validate([
            'product_id'   => ['required', 'exists:products,id'],
            'type'         => ['required', 'in:IN,OUT,ASSIGN,RETURN,SALE'],
            'quantity'     => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'sizes'        => ['nullable', 'array'],   // {"S/M": 3, "X/XL": 7}
            'notes'        => ['nullable', 'string', 'max:255'],
        ]);

        if (!in_array($data['type'], ['IN', 'OUT'], true)) {
            return response()->json([
                'status'  => false,
                'message' => 'El stock de repartidores se asigna y se devuelve desde su almacén (Inventario → Stock Repartidores).',
            ], 422);
        }
        if (!in_array($role, ['Admin', 'Gerente'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado para este tipo de movimiento'], 403);
        }

        $warehouseId = $data['warehouse_id'] ?? Warehouse::where('is_main', true)->value('id');
        if (!$warehouseId) {
            return response()->json(['status' => false, 'message' => 'No hay almacén principal'], 422);
        }

        $sizes = $data['sizes'] ?? null;
        if ($sizes && array_sum(array_map('intval', $sizes)) !== (int) $data['quantity']) {
            return response()->json(['status' => false, 'message' => 'La suma de las tallas no coincide con la cantidad.'], 422);
        }

        try {
            $args = [$data['product_id'], $warehouseId, $data['quantity'], Auth::id(), $data['notes'] ?? 'Movimiento manual', null, null, $sizes];
            $movement = $data['type'] === 'IN' ? $inventory->addStock(...$args) : $inventory->removeStock(...$args);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'   => true,
            'message'  => 'Movimiento de stock registrado correctamente',
            'movement' => $movement->load(['product', 'toWarehouse', 'fromWarehouse']),
        ], 201);
    }

    /** POST /stock/adjust: entrada o salida en el almacén principal (antes cambiaba products.stock). */
    public function adjust(Request $request, InventoryService $inventory)
    {
        if (!in_array(Auth::user()->role?->description, ['Admin', 'Gerente'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado'], 403);
        }
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:IN,OUT',
            'quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:255',
        ]);
        $warehouseId = Warehouse::where('is_main', true)->value('id');
        if (!$warehouseId) {
            return response()->json(['status' => false, 'message' => 'No hay almacén principal'], 422);
        }

        try {
            $args = [$data['product_id'], $warehouseId, $data['quantity'], Auth::id(), $data['reason'] ?? 'Ajuste manual'];
            $movement = $data['type'] === 'IN' ? $inventory->addStock(...$args) : $inventory->removeStock(...$args);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Stock insuficiente'], 422);
        }

        return response()->json(['status' => true, 'message' => 'Stock actualizado', 'movement' => $movement]);
    }
}
