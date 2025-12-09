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

        if (!in_array($role, ['Admin', 'Gerente', 'Master'])) {
            return response()->json([
                'status'  => false,
                'message' => 'No autorizado'
            ], 403);
        }

        $inventory = \App\Models\Inventory::with(['product', 'warehouse'])->get();

        return response()->json([
            'status' => true,
            'data'   => $inventory,
        ]);
    }

    // Stock personal del repartidor (se puede usar después)
    public function myStock(Request $request)
    {
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
