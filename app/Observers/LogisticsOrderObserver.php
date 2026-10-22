<?php

namespace App\Observers;

use App\Constants\LogisticsStatus;
use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\Status;
use App\Services\Logistics\Centers;
use App\Services\Logistics\LogisticsOrders;
use Illuminate\Support\Facades\Auth;

/**
 * Lleva el estado logístico al ritmo de la orden, venga de donde venga el cambio (logística propia, R3):
 * - "Asignar a agencia" sin agencia, en una ciudad con centro activo: la orden entra al centro (§5);
 * - "Entregado": queda entregada (§12);
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
            $this->orders->transition($row, LogisticsStatus::DELIVERED, 'entregada', $actor, null, [], ['delivered_at' => now()]);

            return;
        }
        if ($status === OrderStatus::EN_RUTA || $status === OrderStatus::NOVEDAD_SOLUCIONADA) {
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
            $this->orders->transition($row, LogisticsStatus::RETURN_PENDING, 'retorno_pendiente', $actor, "La orden pasó a \"{$status}\" con la pieza fuera del almacén.");

            return;
        }
        $this->orders->close($row, "La orden pasó a \"{$status}\".", $actor);
    }
}
