<?php

namespace App\Http\Controllers;

use App\Models\AgencyTrip;
use App\Models\Order;
use App\Services\Agencies\AgencyTrips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Carreras de las agencias (tarea 5): las de una orden y la anulación por el Admin. */
class AgencyTripController extends Controller
{
    /** GET /orders/{order}/trips — el acceso a la orden lo cuida order.access. */
    public function index(Order $order): JsonResponse
    {
        $trips = $order->agencyTrips()->with(['order:id,name', 'agency:id,names', 'deliverer:id,names', 'voider:id,names'])->orderBy('id')->get();
        $paid = $trips->whereNull('voided_at');

        return response()->json([
            'status' => true,
            'data' => [
                'trips' => $trips->map->toPayload()->values(),
                'count' => $paid->count(),
                'total_usd' => round($paid->sum('price_usd'), 2),
            ],
        ]);
    }

    /** POST /agency-trips/{trip}/void { reason } — solo Admin/Master: deja de pagarse, pero queda a la vista. */
    public function void(Request $request, AgencyTrip $trip, AgencyTrips $trips): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:255'], ['reason.required' => 'Escribe por qué se anula la carrera.']);
        if ($trip->voided_at) {
            return response()->json(['status' => false, 'message' => 'Esa carrera ya estaba anulada.'], 422);
        }

        $trips->void($trip, Auth::id(), $data['reason']);

        return response()->json([
            'status' => true,
            'message' => 'Carrera anulada',
            'data' => $trip->fresh(['order:id,name', 'agency:id,names', 'deliverer:id,names', 'voider:id,names'])->toPayload(),
        ]);
    }
}
