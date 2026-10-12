<?php

namespace App\Console\Commands;

use App\Jobs\CalculateDeliveryRoute;
use App\Jobs\ResolveOrderLocation;
use App\Models\Order;
use App\Models\OrderDeliveryRoute;
use App\Models\Status;
use App\Services\Delivery\DeliveryRoutes;
use App\Services\Delivery\MapsLinkResolver;
use App\Services\Delivery\PlusCode;
use Illuminate\Console\Command;

/**
 * Reintento automático (Módulo 3 de Fran, §42): las rutas con error de Google y las ubicaciones con enlace corto que
 * no se pudieron abrir. Corre cada hora; la cola ya reintentó antes unos minutos después del fallo.
 */
class DeliveryRetry extends Command
{
    protected $signature = 'delivery:retry {--max-attempts=12}';

    protected $description = 'Reintenta las rutas con error de Google y los enlaces de ubicación pendientes (Módulo 3)';

    public function handle(): int
    {
        $routes = OrderDeliveryRoute::where('calculation_status', OrderDeliveryRoute::ERROR)
            ->where('attempts', '<', (int) $this->option('max-attempts'))
            ->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<', now()->subMinutes(30)))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('trips'))
            ->pluck('id');
        foreach ($routes as $id) {
            CalculateDeliveryRoute::dispatch($id);
        }

        $stage = Status::whereIn('description', DeliveryRoutes::AGENCY_STAGE)->pluck('id');
        $orders = Order::where('location_status', MapsLinkResolver::PENDING)->whereIn('status_id', $stage)
            ->where('updated_at', '>=', now()->subDays(7))->get(['id', 'location']);
        // También los códigos plus que esperaban el almacén de referencia
        $short = $orders->filter(fn ($o) => MapsLinkResolver::isShortLink($o->location) || PlusCode::find($o->location));
        foreach ($short as $order) {
            ResolveOrderLocation::dispatch($order->id);
        }
        $this->info("Rutas reintentadas: {$routes->count()}. Enlaces reintentados: {$short->count()}.");

        return self::SUCCESS;
    }
}
