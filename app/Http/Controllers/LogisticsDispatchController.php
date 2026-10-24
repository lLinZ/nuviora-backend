<?php

namespace App\Http\Controllers;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsIncident;
use App\Models\LogisticsNovelty;
use App\Models\LogisticsOrder;
use App\Services\Logistics\Departures;
use App\Services\Logistics\Dispatcher;
use App\Services\Logistics\Incidents;
use App\Services\Logistics\Novelties;
use App\Services\Logistics\Returns;
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
            'rounds.offers.rider:id,names', 'events.actor:id,names', 'novelties.reporter:id,names', 'custody', 'incidents.rider:id,names']);

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

    /** §9.2: picking y packing. */
    public function prepare(LogisticsOrder $logisticsOrder, Departures $departures)
    {
        $this->authorizeCenter($logisticsOrder);

        return $this->reply($departures->prepare($logisticsOrder, Auth::user()));
    }

    /** §9.2: "Listo para despacho". */
    public function ready(LogisticsOrder $logisticsOrder, Departures $departures)
    {
        $this->authorizeCenter($logisticsOrder);

        return $this->reply($departures->ready($logisticsOrder, Auth::user()));
    }

    /** §11: "Confirmar salida", con el rider ya en el centro y los paquetes de su ruta en la mano. */
    public function depart(LogisticsOrder $logisticsOrder, Departures $departures)
    {
        $this->authorizeCenter($logisticsOrder);

        return $this->reply($departures->depart($logisticsOrder, Auth::user()));
    }

    /** §19: el paquete se le entrega a la agencia de respaldo en el centro. */
    public function handToAgency(LogisticsOrder $logisticsOrder, Departures $departures)
    {
        $this->authorizeCenter($logisticsOrder);

        return $this->reply($departures->handToAgency($logisticsOrder, Auth::user()));
    }

    /** §16.1 punto 4: el operador recibe físicamente el retorno del rider. */
    public function receiveReturn(Request $request, LogisticsOrder $logisticsOrder, Returns $returns)
    {
        $this->authorizeCenter($logisticsOrder);
        $data = $request->validate(['note' => 'nullable|string|max:250']);

        return $this->reply($returns->receive($logisticsOrder, Auth::user(), $data['note'] ?? null));
    }

    /** §16.1 punto 7: administración reingresa la pieza a disponible o la da de baja. */
    public function resolveReturn(Request $request, LogisticsOrder $logisticsOrder, Returns $returns)
    {
        $this->authorizeCenter($logisticsOrder);
        $data = $request->validate(['action' => 'required|in:restock,discard', 'note' => 'nullable|string|max:250']);

        return $this->reply($returns->resolveReview($logisticsOrder, Auth::user(), $data['action'], $data['note'] ?? null));
    }

    /** §14.2: el operador decide que el rider sigue con la ruta. */
    public function incidentContinue(Request $request, LogisticsIncident $incident, Incidents $incidents)
    {
        $this->authorizeCenter($incident->logisticsOrder);
        $data = $request->validate(['note' => 'nullable|string|max:250']);

        return $this->reply($incidents->keepGoing($incident, Auth::user(), $data['note'] ?? null));
    }

    /** §14.2 puntos 3 a 5: "Interrumpir ruta" y buscar reemplazo; pickup = rider (donde está él) o almacen. */
    public function incidentInterrupt(Request $request, LogisticsIncident $incident, Incidents $incidents)
    {
        $this->authorizeCenter($incident->logisticsOrder);
        $data = $request->validate(['pickup' => 'required|in:rider,almacen', 'note' => 'nullable|string|max:250']);

        return $this->reply($incidents->interrupt($incident, Auth::user(), $data['pickup'], $data['note'] ?? null));
    }

    /** §14.3: el operador confirma el traspaso rider → rider (si no lo confirmó el reemplazo en la app). */
    public function transfer(LogisticsOrder $logisticsOrder, Incidents $incidents)
    {
        $this->authorizeCenter($logisticsOrder);

        return $this->reply($incidents->transfer($logisticsOrder, Auth::user()));
    }

    public function commentNovelty(Request $request, LogisticsNovelty $novelty, Novelties $novelties)
    {
        $this->authorizeCenter($novelty->logisticsOrder);
        $data = $request->validate(['text' => 'required|string|max:1000']);

        return response()->json(['status' => true, 'message' => 'Comentario guardado.', 'novelty' => $novelties->comment($novelty, Auth::user(), $data['text'])]);
    }

    public function resolveNovelty(Request $request, LogisticsNovelty $novelty, Novelties $novelties)
    {
        $this->authorizeCenter($novelty->logisticsOrder);
        $data = $request->validate(['result' => 'required|string|max:250']);

        return $this->reply($novelties->resolve($novelty, Auth::user(), $data['result']));
    }

    public function escalateNovelty(Request $request, LogisticsNovelty $novelty, Novelties $novelties)
    {
        $this->authorizeCenter($novelty->logisticsOrder);
        $data = $request->validate(['comment' => 'nullable|string|max:500']);

        return $this->reply($novelties->escalate($novelty, Auth::user(), $data['comment'] ?? null));
    }

    private function reply(array $out)
    {
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
