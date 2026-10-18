<?php

namespace App\Http\Controllers;

use App\Services\Inventory\StockStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Las cifras de stock del Módulo 5 (§14, R1): físico, reservado, disponible, en revisión, en tránsito y por recibir. */
class InventoryStockController extends Controller
{
    public function index(Request $request, StockStatus $status): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'product_id' => 'nullable|integer|exists:products,id',
        ]);
        $rows = $status->rows($data['warehouse_id'] ?? null, $data['product_id'] ?? null);

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'physical' => $rows->sum('physical'),
                'reserved' => $rows->sum('reserved'),
                'available' => $rows->sum('available'),
                'review' => $rows->sum('review'),
                'in_transit' => $rows->sum('in_transit'),
                'incoming' => $rows->sum('incoming'),
            ],
        ]);
    }
}
