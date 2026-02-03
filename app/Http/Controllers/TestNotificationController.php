<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAssignedNotification;
use App\Notifications\OrderNoveltyNotification;
use App\Notifications\OrderNoveltyResolvedNotification;
use App\Notifications\OrderScheduledNotification;
use App\Notifications\OrderWaitingLocationNotification;

class TestNotificationController extends Controller
{
    public function trigger(Request $request)
    {
        $type = $request->input('type');
        $user = auth()->user();
        $order = Order::latest()->first();

        if (!$order) {
            return response()->json(['message' => 'No hay órdenes para probar'], 400);
        }

        switch ($type) {
            case 'assigned':
                $user->notify(new OrderAssignedNotification($order, "[TEST] Se te ha asignado la orden #{$order->name}"));
                break;
            case 'novelty':
                $user->notify(new OrderNoveltyNotification($order, "[TEST] Nueva novedad reportada en orden #{$order->name}"));
                break;
            case 'resolved':
                $user->notify(new OrderNoveltyResolvedNotification($order, "[TEST] Novedad solucionada en orden #{$order->name}"));
                break;
            case 'scheduled':
                $user->notify(new OrderScheduledNotification($order, "[TEST] Orden #{$order->name} programada para más tarde"));
                break;
            case 'waiting':
                $user->notify(new OrderWaitingLocationNotification($order, "[TEST] La orden #{$order->name} lleva más de 30 min esperando ubicación."));
                break;
            default:
                return response()->json(['message' => 'Tipo inválido'], 400);
        }

        return response()->json(['message' => "Notificación {$type} enviada a ti mismo."]);
    }
}
