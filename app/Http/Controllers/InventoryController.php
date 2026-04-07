<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    // Inventario general (Admin / Gerente)
    public function index(Request $request)
    {
        $role = Auth::user()->role?->description;

        if (!in_array($role, ['Admin', 'Gerente', 'Master', 'Agencia'])) {
            return response()->json([
                'status'  => false,
                'message' => 'No autorizado'
            ], 403);
        }

        $query = \App\Models\Inventory::with(['product', 'warehouse']);

        if ($role === 'Agencia') {
            $query->whereHas('warehouse', function ($q) {
                $q->where('user_id', '=', Auth::id());
            });
        }

        if ($request->has('main') && $request->main === 'true') {
            $query->whereHas('warehouse', function ($q) {
                $q->where('is_main', '=', true);
            });
        }

        $rawInventory = $query->get();

        if ($request->has('overview') && $request->overview === 'true') {
            $allProducts = \App\Models\Product::all();
            
            $flattened = [];
            $processedProductIds = [];

            // 1. Process existing inventories
            foreach ($rawInventory as $inv) {
                if (!$inv->product) continue;
                
                $flattened[] = [
                    'product_id'   => $inv->product_id,
                    'product'      => $inv->product->toArray(),
                    'warehouse_id' => $inv->warehouse_id,
                    'warehouse'    => $inv->warehouse ? $inv->warehouse->toArray() : null,
                    'quantity'     => $inv->quantity
                ];
                $processedProductIds[] = $inv->product_id;
            }

            // 2. Add products that have NO inventory record
            $processedProductIds = array_unique($processedProductIds);
            foreach ($allProducts as $p) {
                if (!in_array($p->id, $processedProductIds)) {
                    $flattened[] = [
                        'product_id'   => $p->id,
                        'product'      => $p->toArray(),
                        'warehouse_id' => 0,
                        'warehouse'    => [
                            'name' => 'Sin Stock (General)',
                            'code' => 'N/A'
                        ],
                        'quantity'     => 0
                    ];
                }
            }

            return response()->json([
                'status' => true,
                'data'   => $flattened,
            ]);
        }

        $mappedInventory = $rawInventory->map(function ($inv) {
            return [
                'id'              => $inv->product_id,
                'product_id'      => $inv->product_id,
                'name'            => $inv->product->name ?? $inv->product->title,
                'sku'             => $inv->product->sku,
                'stock_available' => $inv->quantity,
                'warehouse_name'  => $inv->warehouse->name ?? 'N/A',
            ];
        });

        return response()->json([
            'status' => true,
            'data'   => $mappedInventory,
        ]);
    }

    // Stock personal del repartidor (se puede usar después)
    public function myStock(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $role = $user->role?->description;

        if ($role !== 'Repartidor') {
            return response()->json([
                'status'  => false,
                'message' => 'Solo repartidores pueden ver su stock personal',
            ], 403);
        }

        // Movimientos que afectan al repartidor
        $movs = $user->stockMovements()
            ->with('product')
            ->get();

        $grouped = $movs->groupBy('product_id')->map(function ($items, $productId) {
            $product = $items->first()->product;

            $in = $items->whereIn('type', ['ASSIGN'])->sum('quantity');
            $out = $items->whereIn('type', ['RETURN', 'SALE'])->sum('quantity');

            return [
                'product_id' => $productId,
                'name'       => $product->name,
                'sku'        => $product->sku,
                'stock'      => $in - $out,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data'   => $grouped,
        ]);
    }
}
