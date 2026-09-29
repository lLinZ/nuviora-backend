<?php
// app/Http/Controllers/OrderDelivererController.php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\Status;
use Illuminate\Http\Request;

class OrderDelivererController extends Controller
{
    // PUT /orders/{order}/assign-deliverer
    public function assign(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $request->validate([
            'deliverer_id' => 'required|exists:users,id',
        ]);

        $deliverer = User::findOrFail($request->deliverer_id);

        // Validar rol repartidor
        if ($deliverer->role?->description !== 'Repartidor') {
            return response()->json([
                'status'  => false,
                'message' => 'El usuario seleccionado no es un repartidor válido'
            ], 422);
        }

        // Un repartidor de una agencia solo lleva órdenes de esa agencia (tarea 6)
        if ($deliverer->agency_id && $order->agency_id && (int) $deliverer->agency_id !== (int) $order->agency_id) {
            return response()->json([
                'status'  => false,
                'message' => 'Ese repartidor trabaja para otra agencia.'
            ], 422);
        }

        // Cambiamos status a "Asignado a repartidor"
        $statusId = Status::where('description', 'Asignado a repartidor')->value('id');

        $order->update([
            'deliverer_id' => $deliverer->id,
            'status_id'    => $statusId
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Repartidor asignado',
            'order'  => $order->load('client', 'agent', 'deliverer', 'status')
        ]);
    }
}
