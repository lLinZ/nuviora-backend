<?php

namespace App\Observers;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\Status;
use App\Services\Agencies\AgencyTrips;
use App\Services\Inventory\OrderStock;
use Illuminate\Support\Facades\Auth;

/**
 * Stock y carreras de la orden, estén donde estén los cambios (la pantalla de estados, la aprobación de
 * un rechazo o de una cancelación, el cierre de tienda…). Antes solo lo hacía updateStatus.
 * Si algo falla, el cambio de la orden se mantiene: el error queda en el log y en su historial.
 */
class OrderLogisticsObserver
{
    public function updated(Order $order): void
    {
        if ($order->wasChanged('status_id')) {
            $this->safely($order, 'las carreras', function () use ($order) {
                $from = $order->getOriginal('status_id');
                app(AgencyTrips::class)->onStatusChange($order, $from ? (int) $from : null, (int) $order->status_id);
            });
        }

        if ($order->wasChanged(['status_id', 'agency_id', 'warehouse_id'])) {
            $this->safely($order, 'el stock', fn () => app(OrderStock::class)->sync($order));
        }

        if ($order->is_exchange && $order->wasChanged('status_id')
            && Status::whereKey($order->status_id)->value('description') === OrderStatus::ENTREGADO) {
            $this->safely($order, 'la pieza defectuosa', fn () => app(OrderStock::class)->intakeDefective($order));
        }
    }

    private function safely(Order $order, string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
            try {
                OrderActivityLog::create([
                    'order_id' => $order->id,
                    'user_id' => Auth::id(),
                    'action' => 'system_error',
                    'description' => "No se pudo registrar {$what}: {$e->getMessage()}",
                ]);
            } catch (\Throwable) {
                // El log de Laravel ya tiene el error
            }
        }
    }
}
