<?php

namespace App\Services\Delivery;

use App\Constants\OrderStatus;
use App\Jobs\CalculateDeliveryRoute;
use App\Models\AgencyTrip;
use App\Models\Order;
use App\Models\OrderDeliveryRoute;
use App\Models\Status;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * La ruta y el costo esperado de cada orden (Módulo 3 de Fran, §2, §8, §15 a §18, §42, §43):
 * - se calcula cuando la orden queda en manos de una agencia y un almacén (§2, §8): desde "Asignar a agencia";
 * - origen: el almacén de la agencia; destino: la ubicación del cliente ya resuelta (§5 a §7, §18: nunca A → B);
 * - se recalcula si cambian la agencia, el almacén o la ubicación, nunca por un cambio de tarifario (§16);
 * - si nada cambió, se reutiliza la ruta guardada y no se llama a Google (§43);
 * - antes de la primera carrera, la ruta nueva reemplaza a la anterior (§17); después de un intento, la anterior queda
 *   con su carrera y la nueva es otra carrera, que también se paga (§18 y la respuesta de Fran del 2026-10-09);
 * - el tarifario es el vigente el día en que se creó la ruta (§13, §16, §41).
 * Nada de esto lo ve la agencia (§14): no se escribe en el historial de la orden.
 */
class DeliveryRoutes
{
    /** Los estados en que la orden está con la agencia (las columnas de la agencia, más los de revisión y el rechazo). */
    public const AGENCY_STAGE = [
        OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::ASIGNAR_REPARTIDOR, OrderStatus::EN_RUTA,
        OrderStatus::NOVEDADES, OrderStatus::NOVEDAD_SOLUCIONADA, OrderStatus::ENTREGADO, OrderStatus::CAMBIO_UBICACION,
        OrderStatus::POR_APROBAR_UBICACION, OrderStatus::POR_APROBAR_RECHAZO, OrderStatus::RECHAZADO,
    ];

    public function __construct(private GoogleRoutesClient $google, private TariffBook $tariffs)
    {
    }

    public function inAgencyStage(Order $order): bool
    {
        static $names = [];
        $id = (int) $order->status_id;

        return in_array($names[$id] ??= (string) Status::whereKey($id)->value('description'), self::AGENCY_STAGE, true);
    }

    /** El almacén de la agencia para la orden: el de su ciudad; si hay uno solo, ese (§5: no asumir uno por ciudad). */
    public static function warehouseFor(Order $order): ?int
    {
        if (!$order->agency_id) {
            return null;
        }
        $warehouses = Warehouse::where('user_id', $order->agency_id)->orderByDesc('is_active')->orderBy('id')->get(['id', 'city_id', 'is_active']);
        $inCity = $order->city_id ? $warehouses->where('city_id', $order->city_id)->where('is_active', true) : collect();

        return ($inCity->first() ?? $warehouses->first())?->id;
    }

    /**
     * Pone al día la ruta activa de la orden. Devuelve la ruta (la de siempre si nada cambió), o null si la orden todavía
     * no está con una agencia.
     */
    public function refresh(Order $order, string $reason, ?int $userId = null): ?OrderDeliveryRoute
    {
        if (!$order->agency_id || (!$this->inAgencyStage($order) && !$this->wentOut($order))) {
            return null;
        }
        $warehouse = $this->originWarehouse($order);
        $resolved = $order->location_status === MapsLinkResolver::OK && $order->location_lat !== null;
        $inputs = [
            'agency_id' => (int) $order->agency_id,
            'warehouse_id' => $warehouse?->id,
            'city_id' => $order->city_id ?? $warehouse?->city_id,
            'origin_lat' => $warehouse?->latitude,
            'origin_lng' => $warehouse?->longitude,
            'destination_lat' => $resolved ? (float) $order->location_lat : null,
            'destination_lng' => $resolved ? (float) $order->location_lng : null,
            'maps_url' => $order->location,
        ];
        $status = match (true) {
            !$warehouse => OrderDeliveryRoute::NO_WAREHOUSE,
            !$warehouse->hasCoordinates() => OrderDeliveryRoute::WAREHOUSE_NO_COORDS,
            !$resolved => OrderDeliveryRoute::LOCATION_PENDING,
            default => OrderDeliveryRoute::PENDING,
        };

        [$route, $calculate] = DB::transaction(function () use ($order, $reason, $userId, $inputs, $status) {
            $active = OrderDeliveryRoute::where('order_id', $order->id)->where('is_active', true)->lockForUpdate()->latest('id')->first();
            if ($active && self::sameInputs($active, $inputs)) {
                if (!$active->hasDistance() && $active->maps_url !== $inputs['maps_url']) {
                    $active->update(['maps_url' => $inputs['maps_url']]);
                }
                return [$active, false]; // §43: ya existe el cálculo con estos datos
            }

            $used = $active && $active->trips()->whereNull('voided_at')->exists();
            // La carrera sigue siendo la misma si va con la misma agencia y el cliente no se movió (una ubicación que
            // antes no se sabía no cuenta como mudanza)
            $moved = $active && $active->destination_lat !== null && $inputs['destination_lat'] !== null
                && !self::samePoint($active->destination_lat, $active->destination_lng, $inputs['destination_lat'], $inputs['destination_lng']);
            $sameTrip = !$used || ((int) $active->agency_id === $inputs['agency_id'] && !$moved);
            if ($active && !$active->hasDistance() && $sameTrip) {
                // Todavía sin calcular: se completa la misma ruta. También la que ya tiene su carrera, por ejemplo cuando
                // se cargan las coordenadas del almacén: si no, la carrera se quedaría con una ruta sin costo (§29)
                $active->fill($inputs + ['calculation_status' => $status, 'error' => null])->save();
                return [$active, $status === OrderDeliveryRoute::PENDING];
            }

            $destinationChanged = $active && !self::samePoint($active->destination_lat, $active->destination_lng, $inputs['destination_lat'], $inputs['destination_lng']);
            $afterAttempt = $used && $destinationChanged;
            $active?->update(['is_active' => false, 'superseded_at' => now()]);
            $route = OrderDeliveryRoute::create($inputs + [
                'order_id' => $order->id,
                'tariff_date' => now(config('app.timezone'))->toDateString(),
                'calculation_status' => $status,
                'reason' => $reason,
                'created_by' => $userId,
                // §18 con la respuesta de Fran del 2026-10-09: "hay que pagarles esas dos carreras" (almacén → A y
                // almacén → B). No queda para revisar: es otra carrera, con su propio costo.
                'review_note' => $afterAttempt ? 'Dirección nueva después de un intento: es otra carrera desde el almacén, y se pagan las dos.' : null,
            ]);

            return [$route, $status === OrderDeliveryRoute::PENDING];
        });

        if ($calculate) {
            // Con la cola "database", el trabajo se guarda en la misma transacción que la orden: el worker no lo ve
            // hasta que se confirma
            CalculateDeliveryRoute::dispatch($route->id);
        }

        return $route;
    }

    /** Consulta a Google y pone el precio (lo llama la cola). §42: si Google falla, no se inventa la distancia. */
    public function calculate(OrderDeliveryRoute $route): void
    {
        if (!in_array($route->calculation_status, [OrderDeliveryRoute::PENDING, OrderDeliveryRoute::ERROR], true)) {
            return;
        }
        // Una ruta reemplazada antes de calcularse, y que ninguna carrera usó, ya no hace falta
        if (!$route->is_active && !$route->trips()->exists()) {
            return;
        }
        $route->forceFill(['attempts' => $route->attempts + 1, 'last_attempt_at' => now()]);

        try {
            $meters = $this->google->distanceMeters($route->origin_lat, $route->origin_lng, $route->destination_lat, $route->destination_lng);
        } catch (GoogleRoutesException $e) {
            $route->forceFill(['calculation_status' => OrderDeliveryRoute::ERROR, 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
            if ($e->retryable()) {
                throw $e; // la cola lo reintenta (§42)
            }
            return;
        }

        $route->forceFill(['distance_meters' => $meters, 'distance_km' => round($meters / 1000, 3)]);
        $tariff = $route->city_id ? $this->tariffs->find((int) $route->agency_id, (int) $route->city_id, $route->tariff_date->toDateString()) : null;
        $this->tariffs->price($route, $tariff);
    }

    /**
     * Si cambiaron las coordenadas de un almacén, las órdenes en curso que salen de él se recalculan (§16, §39). Las ya
     * cerradas, solo si su ruta todavía no tiene distancia: es su primer cálculo, que el cierre de la semana necesita
     * (§29), y lo ya calculado no cambia.
     */
    public function refreshForWarehouse(Warehouse $warehouse, ?int $userId): int
    {
        $final = Status::whereIn('description', [OrderStatus::ENTREGADO, OrderStatus::CANCELADO, OrderStatus::RECHAZADO])->pluck('id');
        $orders = Order::where(function ($q) use ($warehouse) {
            $q->whereHas('deliveryRoutes', fn ($r) => $r->where('is_active', true)->where('warehouse_id', $warehouse->id));
            if ($warehouse->user_id) {
                // Las de su agencia que se quedaron sin almacén
                $q->orWhere(fn ($o) => $o->where('agency_id', $warehouse->user_id)
                    ->whereHas('deliveryRoutes', fn ($r) => $r->where('is_active', true)->where('calculation_status', OrderDeliveryRoute::NO_WAREHOUSE)));
            }
        })->where(fn ($q) => $q->whereNotIn('status_id', $final)
            ->orWhereHas('deliveryRoutes', fn ($r) => $r->where('is_active', true)->whereNull('distance_meters')))
            ->get();
        foreach ($orders as $order) {
            $this->refresh($order, 'almacen_coordenadas', $userId);
        }
        return $orders->count();
    }

    /**
     * La agencia ya salió con la orden (tiene una carrera). Su ruta hace falta aunque la orden ya no esté con la agencia
     * (cancelada, reprogramada o de vuelta a nueva): esa carrera se paga y el cierre de la semana la compara (§29).
     */
    private function wentOut(Order $order): bool
    {
        return AgencyTrip::where('order_id', $order->id)->where('agency_id', $order->agency_id)->whereNull('voided_at')->exists();
    }

    /**
     * El almacén de donde sale la carrera (§5): el de la orden si es de su agencia. Si no, el de la agencia, como hace el
     * stock (Order::resolveStockWarehouseId): las órdenes asignadas por ciudad o desde Shopify antes del módulo no lo
     * guardaron. No cambia la orden.
     */
    private function originWarehouse(Order $order): ?Warehouse
    {
        $own = $order->warehouse_id ? Warehouse::find($order->warehouse_id) : null;
        if ($own && (int) $own->user_id === (int) $order->agency_id) {
            return $own;
        }
        $agencyWarehouse = self::warehouseFor($order);

        return $agencyWarehouse ? Warehouse::find($agencyWarehouse) : $own;
    }

    private static function sameInputs(OrderDeliveryRoute $r, array $i): bool
    {
        return (int) $r->agency_id === $i['agency_id'] && (int) $r->warehouse_id === (int) $i['warehouse_id']
            && self::samePoint($r->origin_lat, $r->origin_lng, $i['origin_lat'], $i['origin_lng'])
            && self::samePoint($r->destination_lat, $r->destination_lng, $i['destination_lat'], $i['destination_lng']);
    }

    private static function samePoint(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): bool
    {
        if ($lat1 === null || $lat2 === null) {
            return $lat1 === $lat2 && $lng1 === $lng2;
        }
        return abs($lat1 - $lat2) < 0.0000005 && abs($lng1 - $lng2) < 0.0000005;
    }
}
