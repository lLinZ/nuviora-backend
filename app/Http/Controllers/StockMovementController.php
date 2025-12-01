<?php

// app/Http/Controllers/StockMovementController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\StockMovement;
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
    public function store(Request $request)
    {
        $user = Auth::user();
        $role = $user->role?->description; // Admin, Gerente, Vendedor, Repartidor

        $data = $request->validate([
            'product_id'   => ['required', 'exists:products,id'],
            'type'         => ['required', 'in:IN,OUT,ASSIGN,RETURN,SALE'],
            'quantity'     => ['required', 'integer', 'min:1'],
            'deliverer_id' => ['nullable', 'exists:users,id'],
            'order_id'     => ['nullable', 'exists:orders,id'],
        ]);

        // 🔒 Reglas por tipo y rol
        switch ($data['type']) {
            case 'IN':
            case 'OUT':
                // solo Admin / Gerente pueden meter o sacar del stock general
                if (!in_array($role, ['Admin', 'Gerente'])) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'No autorizado para este tipo de movimiento',
                    ], 403);
                }
                break;

            case 'ASSIGN':
                // Asignar stock a repartidor → solo Admin / Gerente
                if (!in_array($role, ['Admin', 'Gerente'])) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'No autorizado para asignar stock a repartidores',
                    ], 403);
                }

                if (empty($data['deliverer_id'])) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'deliverer_id es obligatorio para movimientos ASSIGN',
                    ], 422);
                }
                break;

            case 'RETURN':
            case 'SALE':
                // Devolver o vender pueden hacerlo Admin / Gerente / Repartidor
                if (!in_array($role, ['Admin', 'Gerente', 'Repartidor'])) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'No autorizado para este tipo de movimiento',
                    ], 403);
                }

                // Si viene desde el repartidor, forzamos deliverer_id = user->id
                if ($role === 'Repartidor') {
                    $data['deliverer_id'] = $user->id;
                }
                break;
        }

        // (Opcional) validar que el producto exista y no se vaya a negativo
        $product = Product::findOrFail($data['product_id']);

        // Aquí podrías chequear stock global / stock de repartidor antes de permitir el movimiento
        // de momento solo creamos el registro de movimiento.

        $movement = StockMovement::create([
            'product_id'   => $data['product_id'],
            'type'         => $data['type'],
            'quantity'     => $data['quantity'],
            'deliverer_id' => $data['deliverer_id'] ?? null,
            'order_id'     => $data['order_id'] ?? null,
            'created_by'   => $user->id,
        ]);

        return response()->json([
            'status'   => true,
            'message'  => 'Movimiento de stock registrado correctamente',
            'movement' => $movement->load(['product', 'deliverer', 'order', 'creator']),
        ], 201);
    }
    public function adjust(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:IN,OUT',
            'quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($data) {
            $p = Product::lockForUpdate()->find($data['product_id']);

            $newStock = $data['type'] === 'IN'
                ? $p->stock + $data['quantity']
                : $p->stock - $data['quantity'];

            if ($newStock < 0) {
                return response()->json(['status' => false, 'message' => 'Stock insuficiente'], 422);
            }

            $p->update(['stock' => $newStock]);

            $m = StockMovement::create([
                'product_id' => $p->id,
                'user_id' => Auth::id(),
                'type' => $data['type'],
                'quantity' => $data['quantity'],
                'reason' => $data['reason'] ?? null,
                'meta' => null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Stock actualizado',
                'product' => $p,
                'movement' => $m
            ]);
        });
    }
}
