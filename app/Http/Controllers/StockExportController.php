<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\ProductWarehouseLeadTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET  /reports/stock-export          – current stock with lead times
 * GET  /product-warehouse-lead-times  – list all configured lead times
 * PUT  /product-warehouse-lead-times  – upsert a lead time (product + warehouse)
 */
class StockExportController extends Controller
{
    private function authorizeAdmin()
    {
        $user = Auth::user();
        if (!in_array($user?->role?->description, ['Admin', 'Gerente'])) {
            abort(403, 'No autorizado.');
        }
    }

    /**
     * GET /reports/stock-export
     *
     * Returns every inventory record enriched with product info,
     * warehouse info, and the configured lead time.
     */
    public function stockExport(Request $request)
    {
        $this->authorizeAdmin();

        $inventories = Inventory::with(['product', 'warehouse'])
            ->get();

        // Load all lead times indexed by "product_id-warehouse_id"
        $leadTimes = ProductWarehouseLeadTime::all()
            ->keyBy(fn($lt) => "{$lt->product_id}-{$lt->warehouse_id}");

        $rows = $inventories->map(function ($inv) use ($leadTimes) {
            $product   = $inv->product;
            $warehouse = $inv->warehouse;

            if (!$product || !$warehouse) return null;

            $key      = "{$product->id}-{$warehouse->id}";
            $ltRecord = $leadTimes->get($key);

            // Fallback: product global lead time → default 3
            $leadTimeDays = $ltRecord
                ? $ltRecord->lead_time_days
                : ($product->lead_time_days ?? 3);

            $usefulStock = max(
                0,
                $inv->quantity
                    - ($inv->reserved_stock  ?? 0)
                    - ($inv->defective_stock ?? 0)
                    - ($inv->blocked_stock   ?? 0)
            );

            return [
                'product_id'      => $product->id,
                'product_name'    => $product->showable_name ?? $product->title ?? $product->name,
                'sku'             => $product->sku ?? '',
                'warehouse_id'    => $warehouse->id,
                'warehouse_name'  => $warehouse->name,
                'warehouse_location' => $warehouse->location ?? '',
                'quantity'        => (int) $inv->quantity,
                'useful_stock'    => $usefulStock,
                'reserved_stock'  => (int) ($inv->reserved_stock  ?? 0),
                'defective_stock' => (int) ($inv->defective_stock ?? 0),
                'blocked_stock'   => (int) ($inv->blocked_stock   ?? 0),
                'lead_time_days'  => $leadTimeDays,
                'lead_time_source'=> $ltRecord ? 'custom' : 'default',
            ];
        })
        ->filter()
        ->sortBy(['warehouse_name', 'product_name'])
        ->values();

        return response()->json([
            'status' => true,
            'total'  => $rows->count(),
            'data'   => $rows,
        ]);
    }

    /**
     * GET /product-warehouse-lead-times
     * Returns all configured lead time overrides.
     */
    public function listLeadTimes(Request $request)
    {
        $this->authorizeAdmin();

        $items = ProductWarehouseLeadTime::with(['product', 'warehouse'])->get();

        return response()->json([
            'status' => true,
            'data'   => $items,
        ]);
    }

    /**
     * PUT /product-warehouse-lead-times
     *
     * Body: { product_id, warehouse_id, lead_time_days }
     * Creates or updates the record.
     */
    public function upsertLeadTime(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'product_id'     => 'required|integer|exists:products,id',
            'warehouse_id'   => 'required|integer|exists:warehouses,id',
            'lead_time_days' => 'required|integer|min:1|max:365',
        ]);

        $lt = ProductWarehouseLeadTime::updateOrCreate(
            [
                'product_id'   => $data['product_id'],
                'warehouse_id' => $data['warehouse_id'],
            ],
            [
                'lead_time_days' => $data['lead_time_days'],
            ]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Lead time actualizado.',
            'data'    => $lt,
        ]);
    }
}
