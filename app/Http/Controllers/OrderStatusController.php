<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting; // si la usas luego para tasa
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderStatusController extends Controller
{
    //
    public function updateStatus(Request $request, Order $order)
    {
        $request->validate(['status_id' => 'required|integer|exists:statuses,id']);
        $newStatusId = $request->status_id;

        DB::transaction(function () use ($order, $newStatusId) {
            $order->update(['status_id' => $newStatusId]);

            $status = $order->status()->first(); // después del update
            $desc = $status?->description;

            $rate = 1; // “Tasa: 1” ahora mismo
            $today = now()->toDateString();

            // Vendedora: $1 al pasar a Confirmado
            if ($desc === 'Confirmado' && $order->agent_id) {
                Commission::firstOrCreate(
                    ['user_id' => $order->agent_id, 'order_id' => $order->id, 'role' => 'Vendedor'],
                    ['date' => $today, 'amount_usd' => 1, 'currency' => 'USD', 'rate' => $rate, 'amount_local' => 1 * $rate]
                );
            }

            // Entregado: $2.5 repartidor + $0.5 gerente
            if ($desc === 'Entregado') {
                if ($order->deliverer_id) {
                    Commission::firstOrCreate(
                        ['user_id' => $order->deliverer_id, 'order_id' => $order->id, 'role' => 'Repartidor'],
                        ['date' => $today, 'amount_usd' => 2.5, 'currency' => 'USD', 'rate' => $rate, 'amount_local' => 2.5 * $rate]
                    );
                }
                // Gerente: cualquiera con rol Gerente (si hay varios, el 1ro)
                $managerId = \App\Models\User::whereHas('role', fn($q) => $q->where('description', 'Gerente'))
                    ->value('id');
                if ($managerId) {
                    Commission::firstOrCreate(
                        ['user_id' => $managerId, 'order_id' => $order->id, 'role' => 'Gerente'],
                        ['date' => $today, 'amount_usd' => 0.5, 'currency' => 'USD', 'rate' => $rate, 'amount_local' => 0.5 * $rate]
                    );
                }
            }
        });

        return response()->json(['status' => true, 'message' => 'Estado actualizado', 'order' => $order->load(['status', 'agent', 'client', 'deliverer'])]);
    }
}
