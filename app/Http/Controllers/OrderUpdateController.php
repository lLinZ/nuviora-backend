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
        $order->load(['client', 'status', 'agent', 'updates.user', 'products.product']);

        return response()->json([
            'status' => true,
            'order' => $order
        ]);
    }

    // Crear una actualización
    public function store(Request $request, Order $order)
    {
        $request->validate([
            'message' => 'required|string|max:1000'
        ]);

        $update = OrderUpdate::create([
            'order_id' => $order->id,
            'user_id'  => Auth::id(), // o el id del usuario logueado
            'message'  => $request->message
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Actualización creada correctamente',
            'update' => $update->load('user')
        ]);
    }
}
