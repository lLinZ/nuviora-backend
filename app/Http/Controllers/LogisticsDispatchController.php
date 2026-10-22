<?php

namespace App\Http\Controllers;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsOrder;
use App\Services\Logistics\Dispatcher;
use App\Services\Logistics\Shifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lo que el operador hace con una orden que busca rider (logística propia, §8.4, §19.1, §21): verla con sus rondas,
 * asignarla a mano a un rider que dijo que sí por teléfono, o pasarla a la agencia antes del minuto 5. Los permisos se
 * validan aquí (§33).
 */
class LogisticsDispatchController extends Controller
{
    public function __construct(private Dispatcher $dispatcher, private Shifts $shifts)
    {
    }

    public function show(LogisticsOrder $logisticsOrder)
    {
        $this->authorizeCenter($logisticsOrder);
        $logisticsOrder->load(['order:id,name,current_total_price,status_id', 'order.status:id,description', 'rider:id,names', 'agency:id,names',
            'rounds.offers.rider:id,names', 'events.actor:id,names']);

        return response()->json([
            'status' => true,
            'order' => $logisticsOrder->toArray() + [
                'status_label' => LogisticsStatus::label($logisticsOrder->status),
                'sla_minutes' => $logisticsOrder->slaMinutes(),
                'searching_seconds' => $logisticsOrder->searching_started_at && $logisticsOrder->status === LogisticsStatus::SEARCHING
                    ? (int) $logisticsOrder->searching_started_at->diffInSeconds(now()) : null,
            ],
        ]);
    }

    /** §8.4: "Solo cuando el rider responde afirmativamente, el operador pulsa 'Asignar manualmente a [rider]'". */
    public function assign(Request $request, LogisticsOrder $logisticsOrder)
    {
        $this->authorizeCenter($logisticsOrder);
        $data = $request->validate(['rider_id' => 'required|integer|exists:users,id', 'comment' => 'nullable|string|max:250']);
        $out = $this->dispatcher->assignManually($logisticsOrder, (int) $data['rider_id'], Auth::user(), $data['comment'] ?? null);

        return response()->json(['status' => $out['ok'], 'message' => $out['message']], $out['code']);
    }

    /** §19.1: el operador la pasa a la agencia antes del minuto 5 (ningún rider llega a tiempo o la flota está llena). */
    public function toAgency(Request $request, LogisticsOrder $logisticsOrder)
    {
        $this->authorizeCenter($logisticsOrder);
        $data = $request->validate(['reason' => 'required|string|max:250']);
        $out = $this->dispatcher->toAgency($logisticsOrder, Auth::user(), 'forzada_por_operador', $data['reason']);

        return response()->json(['status' => $out['ok'], 'message' => $out['message']], $out['code']);
    }

    private function authorizeCenter(LogisticsOrder $row): void
    {
        $user = Auth::user();
        if (!Shifts::isAdmin($user) && !$this->shifts->isOperatorOf($user, $row->center)) {
            abort(response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403));
        }
    }
}
