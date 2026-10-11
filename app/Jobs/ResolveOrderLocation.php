<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderLocationHistory;
use App\Services\Delivery\DeliveryRoutes;
use App\Services\Delivery\OrderLocations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Abre el enlace corto de Maps de una orden para sacar las coordenadas (Módulo 3 de Fran, §7) y, con eso, pone al día
 * su ruta (§16, §17). Va por la cola "delivery".
 */
class ResolveOrderLocation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 40;

    public function __construct(public int $orderId, public ?int $historyId = null)
    {
        $this->onQueue('delivery');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OrderLocations $locations, DeliveryRoutes $routes): void
    {
        $order = Order::find($this->orderId);
        if (!$order) {
            return;
        }
        $history = $this->historyId ? OrderLocationHistory::find($this->historyId) : null;
        // Si la ubicación cambió otra vez mientras esperaba, esta versión ya no importa: la nueva tiene su propio trabajo
        if ($history && $history->new_url !== $order->location) {
            return;
        }
        $locations->resolveNow($order, $history);
        $routes->refresh($order->refresh(), 'cambio_ubicacion');
    }
}
