<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderCancellation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderCancellationController extends Controller
{
    // solicitar cancelación
    public function store(Request $request, Order $order)
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $cancellation = OrderCancellation::create([
            'order_id' => $order->id,
            'user_id'  => Auth::id(),
            'reason'   => $request->reason,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Cancelación solicitada',
            'cancellation' => $cancellation
        ]);
    }

    // aprobar/rechazar
    public function review(Request $request, OrderCancellation $cancellation)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected'
        ]);

        $cancellation->update([
            'status' => $request->status,
            'reviewed_by' => Auth::id(),
        ]);

        // si se aprueba, actualizamos estado de la orden a "Cancelado"
        if ($request->status === 'approved') {
            $cancellation->order->update(['status_id' => /* id de Cancelado */ 15]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Cancelación revisada',
            'cancellation' => $cancellation
        ]);
    }
}
