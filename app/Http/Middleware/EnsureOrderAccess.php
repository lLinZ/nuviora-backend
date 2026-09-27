<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Services\Orders\OrderAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechaza (403) las acciones sobre un pedido que no le corresponde a quien llama (ver OrderAccess).
 * Solo restringe: si pasa, el controlador sigue aplicando sus propias reglas (rol, estado, flujo).
 * Toma el pedido del parámetro {order}, {id} u {orderId} de la ruta.
 */
class EnsureOrderAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $param = $request->route('order') ?? $request->route('id') ?? $request->route('orderId');
        $order = $param instanceof Order ? $param : ($param !== null ? Order::with('status:id,description')->find($param) : null);

        if ($order && !OrderAccess::can($request->user(), $order)) {
            return response()->json(['status' => false, 'message' => 'No tienes permiso para esta orden.'], 403);
        }

        return $next($request);
    }
}
