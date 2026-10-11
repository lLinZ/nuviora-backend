<?php

namespace App\Jobs;

use App\Models\OrderDeliveryRoute;
use App\Services\Delivery\DeliveryRoutes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pide la distancia a Google Routes y pone el costo esperado de una ruta (Módulo 3 de Fran, §8). Va por la cola
 * "delivery", que procesa un worker propio: no compite con la revisión de los comprobantes. Si Google falla, se
 * reintenta sola (§42) y después cada hora con delivery:retry.
 */
class CalculateDeliveryRoute implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public int $uniqueFor = 600;

    public function __construct(public int $routeId)
    {
        $this->onQueue('delivery');
    }

    public function uniqueId(): string
    {
        return (string) $this->routeId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(DeliveryRoutes $routes): void
    {
        if ($route = OrderDeliveryRoute::find($this->routeId)) {
            $routes->calculate($route);
        }
    }
}
