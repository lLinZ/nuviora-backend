<?php

namespace App\Console\Commands;

use App\Models\AgencyTrip;
use App\Models\Order;
use App\Models\Status;
use App\Services\Delivery\DeliveryRoutes;
use App\Services\Delivery\OrderLocations;
use Illuminate\Console\Command;

/**
 * Para las órdenes que ya estaban con una agencia antes del Módulo 3: resuelve la ubicación del cliente (§7) y, con
 * --routes, crea su ruta (§8). No toca las que ya tienen ruta con los mismos datos (§43).
 */
class DeliveryBackfill extends Command
{
    protected $signature = 'delivery:backfill {--days=14 : órdenes con cambios en los últimos N días} {--routes : también calcular las rutas} {--limit=0}';

    protected $description = 'Resuelve las ubicaciones y calcula las rutas de las órdenes en curso con agencia (Módulo 3)';

    public function handle(OrderLocations $locations, DeliveryRoutes $routes): int
    {
        $stage = Status::whereIn('description', DeliveryRoutes::AGENCY_STAGE)->pluck('id');
        $query = Order::whereNotNull('agency_id')->whereIn('status_id', $stage)
            ->where('updated_at', '>=', now()->subDays((int) $this->option('days')))->orderBy('id');
        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $counts = ['ubicaciones' => 0, 'rutas' => 0, 'carreras' => 0];
        foreach ($query->get() as $order) {
            if (trim((string) $order->location) !== '' && $order->location_status === null) {
                $locations->resolveNow($order);
                $counts['ubicaciones']++;
            }
            if ($this->option('routes') && ($route = $routes->refresh($order->refresh(), 'carga_inicial'))) {
                $counts['rutas']++;
                // Las carreras de antes del módulo no tienen ruta: toman la de su orden con esa agencia, para que el
                // cierre de la semana (§29) tenga con qué comparar
                $counts['carreras'] += AgencyTrip::where('order_id', $order->id)->where('agency_id', $route->agency_id)
                    ->whereNull('delivery_route_id')->whereNull('voided_at')->update(['delivery_route_id' => $route->id]);
            }
        }
        $this->info("Ubicaciones resueltas: {$counts['ubicaciones']}. Rutas al día: {$counts['rutas']}. Carreras enlazadas a su ruta: {$counts['carreras']}.");

        return self::SUCCESS;
    }
}
