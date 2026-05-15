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
                    'product'      => $inv->product,
                    'warehouse_id' => $inv->warehouse_id,
                    'warehouse'    => $inv->warehouse ? $inv->warehouse->toArray() : null,
                    'quantity'     => $inv->quantity,
                    'sizes_stock'  => $inv->sizes_stock ?? [],
                    'available_sizes' => $inv->product?->available_sizes ?? []
                ];
                $processedProductIds[] = $inv->product_id;
            }

            // 2. Add products that have NO inventory record
            $processedProductIds = array_unique($processedProductIds);
            foreach ($allProducts as $p) {
                if (!in_array($p->id, $processedProductIds)) {
                    $flattened[] = [
                        'product_id'   => $p->id,
                        'product'      => $p,
                        'warehouse_id' => 0,
                        'warehouse'    => [
                            'name' => 'Sin Stock (General)',
                            'code' => 'N/A'
                        ],
                        'quantity'     => 0,
                        'sizes_stock'  => [],
                        'available_sizes' => $p->available_sizes ?? []
                    ];
                }
            }

            return response()->json([
                'status' => true,
                'data'   => $flattened,
            ]);
        }

        $rawInventory = \App\Models\Inventory::with(['product', 'warehouse'])->get();
        $allProducts = Product::all();
        $processedProductIds = [];
        $mappedInventory = [];

        // 1. Procesar registros existentes
        foreach ($rawInventory as $inv) {
            $mappedInventory[] = [
                'id'              => $inv->id,
                'inventory_id'    => $inv->id,
                'product_id'      => $inv->product_id,
                'product'         => $inv->product,
                'name'            => $inv->product?->name ?? 'Sin nombre',
                'sku'             => $inv->product?->sku ?? 'S/SKU',
                'stock_available' => $inv->quantity,
                'sizes_stock'     => $inv->sizes_stock ?? [],
                'available_sizes' => $inv->product?->available_sizes ?? [],
                'warehouse_name'  => $inv->warehouse?->name ?? 'N/A',
            ];
            $processedProductIds[] = $inv->product_id;
        }

        // 2. Añadir productos sin inventario para que salgan en los tests
        foreach ($allProducts as $p) {
            if (!in_array($p->id, $processedProductIds)) {
                $mappedInventory[] = [
                    'id'              => 0,
                    'inventory_id'    => 0,
                    'product_id'      => $p->id,
                    'product'         => $p,
                    'name'            => $p->title ?? $p->name ?? 'Sin nombre',
                    'sku'             => $p->sku ?? 'S/SKU',
                    'stock_available' => 0,
                    'sizes_stock'     => [],
                    'available_sizes' => $p->available_sizes ?? [],
                    'warehouse_name'  => 'Sin Stock',
                ];
            }
        }

        // 3. Ordenar alfabéticamente por nombre
        usort($mappedInventory, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
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
