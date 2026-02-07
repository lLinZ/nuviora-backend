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
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $data = $request->validate([
            'scheduled_for'      => 'required|date',
            'reason'             => 'nullable|string|max:2000',
            'novedad_resolution' => 'nullable|string|max:2000',
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

        // Persistimos en la orden (incluye scheduled_for)
        $updateData = [
            'status_id'     => $statusId,
            'scheduled_for' => $data['scheduled_for'],
        ];

        if ($request->filled('novedad_resolution')) {
            $updateData['novedad_resolution'] = $request->novedad_resolution;
        }

        // NOTA: La vendedora mantiene la orden hasta que se cierre la tienda.
        // La desasignación ocurre en el comando de cierre de tienda, no aquí al instante.
        /* 
        if (!$scheduled->isToday()) {
            $updateData['agent_id'] = null;
        } 
        */

        $order->update($updateData);

        // 🔔 Evento Global para actualizar Kanban
        event(new \App\Events\OrderUpdated($order));

        // 🔔 Notificar si es "Programado para mas tarde"
        if ($statusDesc === 'Programado para mas tarde') {
             // Notificar a Admins/Gerentes
             $admins = \App\Models\User::whereHas('role', function($q){ $q->whereIn('description', ['Admin', 'Gerente']); })->get();
             foreach ($admins as $admin) {
                 $admin->notify(new \App\Notifications\OrderScheduledNotification($order, "Orden #{$order->name} programada para más tarde"));
             }
        }

        return response()->json([
            'status' => true,
            'message' => 'Orden pospuesta correctamente',
            'order'  => $order->load('client', 'agent', 'status', 'postponements'),
            'postponement' => $postponement,
        ]);
    }
}
