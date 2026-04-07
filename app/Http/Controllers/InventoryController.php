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
            $products = \App\Models\Product::with(['inventories' => function ($q) use ($role) {
                if ($role === 'Agencia') {
                    $q->whereHas('warehouse', function ($innerQ) {
                        $innerQ->where('user_id', '=', Auth::id());
                    });
                }
            }, 'inventories.warehouse'])->get();

            $flattened = [];
            foreach ($products as $p) {
                if ($p->inventories->isEmpty()) {
                    $flattened[] = [
                        'product_id' => $p->id,
                        'product'    => $p,
                        'warehouse_id' => 0,
                        'warehouse'  => (object)[
                            'name' => 'Sin Stock (General)',
                            'code' => 'N/A'
                        ],
                        'quantity'   => 0
                    ];
                } else {
                    foreach ($p->inventories as $inv) {
                        // Ensure product object is present for frontend grouping
                        $inv->product = $p;
                        $flattened[] = $inv;
                    }
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
