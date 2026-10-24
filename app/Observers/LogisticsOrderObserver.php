<?php

namespace App\Observers;

use App\Constants\LogisticsStatus;
use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\Status;
use App\Services\Logistics\Centers;
use App\Services\Logistics\Departures;
use App\Services\Logistics\LogisticsOrders;
use App\Services\Logistics\Novelties;
use App\Models\LogisticsIncident;
use App\Services\Logistics\Dispatcher;
use App\Services\Logistics\RiderCash;
use App\Services\Logistics\RiderPay;
use Illuminate\Support\Facades\Auth;

/**
 * Lleva el estado logístico al ritmo de la orden, venga de donde venga el cambio (logística propia, R3):
 * - "Asignar a agencia" sin agencia, en una ciudad con centro activo: la orden entra al centro (§5);
 * - "Entregado": queda entregada y se cierra la custodia (§12, §16.2.2);
 * - "Novedad Solucionada": la vendedora resolvió lo que el operador escaló; la orden vuelve a la calle (§13);
 * - si vuelve atrás (a la vendedora, cancelada, rechazada, reprogramada…) antes de salir del almacén, sale del centro;
 *   si ya había salido con el rider, la pieza queda como retorno pendiente (§16.1; R5: una reprogramada la guarda);
 * - "Novedades": la novedad ya la tiene la vendedora (el operador la escaló, §13).
 * Si algo de esto falla, la orden se guarda igual: el error queda en el log (como el stock y las carreras).
 */
class LogisticsOrderObserver
{
    public function __construct(private Centers $centers, private LogisticsOrders $orders)
    {
    }

    public function updated(Order $order): void
    {
        if (!$order->wasChanged('status_id')) {
            return;
        }
        try {
            $this->sync($order);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function sync(Order $order): void
    {
        $status = Status::whereKey($order->status_id)->value('description');
        $actor = Auth::id();
        $row = $this->orders->active($order);

        if ($status === OrderStatus::ASIGNAR_A_AGENCIA) {
            if (!$row && !$order->agency_id && ($center = $this->centers->forOrder($order))) {
                $this->orders->enter($order, $center, $actor);
            }

            return;
        }
        if (!$row) {
            return;
        }

        if ($status === OrderStatus::ENTREGADO) {
            // §16.2.2 punto 5: la custodia se cierra como salida definitiva (de quien la tenía al cliente)
            [$fromType, $fromId] = $row->rider_id ? ['rider', $row->rider_id] : ['agencia', $row->agency_id];
            if ($row->departed_at || $row->agency_id) {
                app(Departures::class)->custody($row, $order, $fromType, $fromId, 'cliente', $order->client_id, $actor);
            }
            $this->orders->transition($row, LogisticsStatus::DELIVERED, 'entregada', $actor, null, [], ['delivered_at' => now()]);
            // §17: si cobró en efectivo, ese efectivo queda bajo su custodia
            if ($row->rider_id && $row->departed_at) {
                app(RiderCash::class)->recordDelivery($row, $order, $actor);
            }

            return;
        }
        if ($status === OrderStatus::NOVEDAD_SOLUCIONADA) {
            app(Novelties::class)->solvedBySales($row, $actor);

            return;
        }
        if ($status === OrderStatus::EN_RUTA) {
            return;
        }
        if ($status === OrderStatus::NOVEDADES) {
            if ($row->status !== LogisticsStatus::INCIDENT) {
                $this->orders->transition($row, LogisticsStatus::INCIDENT, 'novedad_con_ventas', $actor, 'La orden pasó a "Novedades".');
            }

            return;
        }

        // Cualquier otro estado la saca del flujo logístico
        if ($row->departed_at && $row->status !== LogisticsStatus::AGENCY) {
            // Ya era un retorno (por ejemplo, reprogramada y después cancelada): el plazo no vuelve a empezar
            if ($row->status === LogisticsStatus::RETURN_PENDING) {
                $this->orders->event($row, 'retorno_pendiente', $row->status, $row->status, $actor, "La orden pasó a \"{$status}\"; la pieza sigue fuera.");

                return;
            }
            // §14: si buscaba reemplazo, el retorno lo hace el rider que todavía tiene el paquete
            $wasSearching = $row->status === LogisticsStatus::SEARCHING;
            $holder = $row->pickup_rider_id ? ['rider_id' => $row->pickup_rider_id, 'pickup_rider_id' => null] : [];
            $this->orders->transition($row, LogisticsStatus::RETURN_PENDING, 'retorno_pendiente', $actor, "La orden pasó a \"{$status}\" con la pieza fuera del almacén.",
                [], ['return_pending_at' => now()] + $holder);
            if ($holder) {
                if ($wasSearching) {
                    app(Dispatcher::class)->cancelOpenOffers($row);
                }
                LogisticsIncident::where('logistics_order_id', $row->id)->where('status', LogisticsIncident::INTERRUPTED)
                    ->update(['status' => LogisticsIncident::CLOSED, 'resolved_at' => now(), 'result' => "La orden pasó a \"{$status}\": el paquete vuelve al centro."]);
            }
            // §18.4, R7: si llegó al cliente y no le recibieron, se le paga la ida
            app(RiderPay::class)->recordFailedLeg($row);

            return;
        }
        $this->orders->close($row, "La orden pasó a \"{$status}\".", $actor);
    }
}
