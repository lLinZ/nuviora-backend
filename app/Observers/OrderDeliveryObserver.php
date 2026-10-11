<?php

namespace App\Observers;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\Status;
use App\Models\Warehouse;
use App\Services\Delivery\DeliveryRoutes;
use App\Services\Delivery\OrderLocations;
use Illuminate\Support\Facades\Auth;

/**
 * Control interno de tarifas de delivery (Módulo 3 de Fran), venga de donde venga el cambio de la orden:
 * - la orden guarda explícitamente su agencia y su almacén (§5);
 * - cada cambio de ubicación queda en el historial y se resuelven las coordenadas (§7, §19);
 * - la ruta y el costo esperado se calculan al pasar a la agencia y se rehacen si cambian la agencia, el almacén o la
 *   ubicación (§8, §16 a §18).
 * Si algo de esto falla, la orden se guarda igual: el error queda en el log (como el stock y las carreras).
 */
class OrderDeliveryObserver
{
    public function __construct(private OrderLocations $locations, private DeliveryRoutes $routes)
    {
    }

    public function saving(Order $order): void
    {
        // §5: con agencia, siempre su almacén (hoy solo lo ponía el reparto automático)
        if (!$order->agency_id) {
            return;
        }
        $belongs = $order->warehouse_id && Warehouse::whereKey($order->warehouse_id)->where('user_id', $order->agency_id)->exists();
        if (!$belongs && ($warehouseId = DeliveryRoutes::warehouseFor($order))) {
            $order->warehouse_id = $warehouseId;
        }
    }

    public function created(Order $order): void
    {
        $this->safely(function () use ($order) {
            if (trim((string) $order->location) !== '') {
                $this->locations->changed($order, null, null, null, Auth::id(), null);
            }
            $this->routes->refresh($order->fresh() ?? $order, 'asignacion', Auth::id());
        });
    }

    public function updated(Order $order): void
    {
        $this->safely(function () use ($order) {
            if ($order->wasChanged('location')) {
                $this->locations->changed($order, $order->getOriginal('location'), $order->getOriginal('location_lat'),
                    $order->getOriginal('location_lng'), Auth::id(), $this->reason($order));
            }
            if ($order->wasChanged(['location', 'agency_id', 'warehouse_id', 'status_id'])) {
                $reason = match (true) {
                    $order->wasChanged('agency_id') => 'cambio_agencia',
                    // Ponerle el almacén a una orden que no lo tenía no es un cambio de almacén
                    $order->wasChanged('warehouse_id') && $order->getOriginal('warehouse_id') !== null => 'cambio_almacen',
                    $order->wasChanged('location') => 'cambio_ubicacion',
                    default => 'asignacion',
                };
                // De la base: la cola pudo haber resuelto ya la ubicación (con la cola síncrona, antes de llegar aquí)
                $this->routes->refresh($order->fresh() ?? $order, $reason, Auth::id());
            }
        });
    }

    /** El motivo del cambio de ubicación (§19: "motivo/novedad cuando exista"). */
    private function reason(Order $order): ?string
    {
        $given = request()?->input('location_reason');
        if (is_string($given) && trim($given) !== '') {
            return mb_substr(trim($given), 0, 300);
        }
        $status = Status::whereKey($order->status_id)->value('description');

        return in_array($status, [OrderStatus::NOVEDADES, OrderStatus::NOVEDAD_SOLUCIONADA, OrderStatus::CAMBIO_UBICACION, OrderStatus::POR_APROBAR_UBICACION], true)
            ? "Estado de la orden: {$status}" : null;
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
