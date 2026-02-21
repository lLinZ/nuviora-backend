<?php

// app/Http/Controllers/OrderUpdateController.php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderUpdateController extends Controller
{
    // Obtener detalle de la orden con relaciones
    public function show(Order $order)
    {
        $order->load(['client', 'status', 'agent', 'deliverer', 'updates.user.role', 'products.product']);

        return response()->json([
            'status' => true,
            'order' => $order
        ]);
    }

    public function store(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $request->validate([
            'message' => 'required|string|max:1000',
            'image'   => 'nullable|image|max:4096', // hasta 4MB
        ]);

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('order-updates', 'public');
        }

        $update = OrderUpdate::create([
            'order_id'   => $order->id,
            'user_id'    => Auth::id(),
            'message'    => $request->message,
            'image_path' => $imagePath,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Actualización creada correctamente',
            'update'  => $update->load(['user', 'user.role']),
        ]);
    }
}
