<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderCancellationController extends Controller
{
    public function index(Request $request)
    {
        $query = OrderCancellation::with(['order', 'user'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'status' => true,
            'data' => $query->get()
        ]);
    }
    // solicitar cancelación
    public function store(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        $pendingId = Status::where('description', 'Pendiente Cancelación')->value('id');
        $cancellation = OrderCancellation::create([
            'order_id' => $order->id,
            'user_id'  => Auth::id(),
            'reason'   => $request->reason,
            'status'   => 'pending',
            'previous_status_id' => $order->status_id, // 👈 guardamos status previo
        ]);

        // mover a "Pendiente Cancelación"
        $order->update(['status_id' => $pendingId]);

        return response()->json([
            'status' => true,
            'message' => 'Cancelación solicitada',
            'cancellation' => $cancellation,
            'order' => $order->load('status')
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
            $canceladoStatus = Status::where('description', 'Cancelado')->first();
            if ($canceladoStatus) {
                $cancellation->order->update(['status_id' => $canceladoStatus->id]);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Cancelación revisada',
            'cancellation' => $cancellation
        ]);
    }
}
