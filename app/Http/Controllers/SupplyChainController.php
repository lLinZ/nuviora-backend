<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Services\SCM\SupplyChainEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupplyChainController extends Controller
{
    public function __construct(protected SupplyChainEngine $engine) {}

    /**
     * GET /api/scm/dashboard
     * Retorna el análisis completo de todos los productos en inventario.
     */
    public function dashboard(Request $request)
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente', 'Master', 'SuperAdmin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        try {
            $productId = $request->query('product_id');
            $data = $this->engine->analyze($productId ? (int) $productId : null);

            return response()->json([
                'status' => true,
                'data'   => $data,
                'meta'   => [
                    'total'        => count($data),
                    'red_count'    => count(array_filter($data, fn($r) => $r['priority'] === 'red')),
                    'orange_count' => count(array_filter($data, fn($r) => $r['priority'] === 'orange')),
                    'yellow_count' => count(array_filter($data, fn($r) => $r['priority'] === 'yellow')),
                    'green_count'  => count(array_filter($data, fn($r) => $r['priority'] === 'green')),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SCM Dashboard error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'status'  => false,
                'message' => 'Error en el motor SCM: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/scm/inventory/{id}
     * Actualiza los campos SCM de un registro de inventario (reservado, defectuoso, bloqueado).
     * Solo lectura en la Fase 1, pero habilitamos la edición de métricas ya que no afecta ventas.
     */
    public function updateInventoryScm(Request $request, int $id)
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente', 'Master', 'SuperAdmin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $inventory = Inventory::findOrFail($id);

        $data = $request->validate([
            'reserved_stock'  => 'nullable|integer|min:0',
            'defective_stock' => 'nullable|integer|min:0',
            'blocked_stock'   => 'nullable|integer|min:0',
        ]);

        $inventory->update(array_filter($data, fn($v) => !is_null($v)));

        return response()->json([
            'status'    => true,
            'inventory' => $inventory->fresh(),
        ]);
    }

    /**
     * PUT /api/scm/products/{id}
     * Actualiza los campos SCM de un producto (lead_time_days, defect_percentage).
     */
    public function updateProductScm(Request $request, int $id)
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente', 'Master', 'SuperAdmin'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $product = Product::findOrFail($id);

        $data = $request->validate([
            'lead_time_days'    => 'nullable|integer|min:1|max:365',
            'defect_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $product->update(array_filter($data, fn($v) => !is_null($v)));

        return response()->json([
            'status'  => true,
            'product' => $product->fresh(),
        ]);
    }
}
