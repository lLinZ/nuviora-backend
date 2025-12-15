<?php

// app/Http/Controllers/OrderPostponementController.php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderPostponement;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderPostponementController extends Controller
{
    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'scheduled_for' => 'required|date', // ISO 8601 o datetime local
            'reason'        => 'nullable|string|max:2000',
        ]);

        // Guardamos historial
        $postponement = OrderPostponement::create([
            'order_id'     => $order->id,
            'user_id'      => Auth::id(),
            'reason'       => $data['reason'] ?? null,
            'scheduled_for' => $data['scheduled_for'],
        ]);

        // Decidimos el status según el día (simple y útil):
        // - Si es hoy mismo => "Programado para mas tarde"
        // - Si es otro día => "Reprogramado"
        $scheduled = \Carbon\Carbon::parse($data['scheduled_for']);
        $statusDesc = $scheduled->isToday() ? 'Programado para mas tarde' : 'Programado para otro dia';
        $statusId   = Status::where('description', $statusDesc)->value('id');

        // Persistimos en la orden (incluye scheduled_for si agregaste columna)
        $order->update([
            'status_id'    => $statusId,
            'scheduled_for' => $data['scheduled_for'],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Orden pospuesta correctamente',
            'order'  => $order->load('client', 'agent', 'status', 'postponements'),
            'postponement' => $postponement,
        ]);
    }
}
