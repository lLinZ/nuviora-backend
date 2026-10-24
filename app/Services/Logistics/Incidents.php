<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsIncident;
use App\Models\LogisticsNovelty;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Las incidencias graves del rider (§14). "Una ruta cerrada NO se modifica por conveniencia. Sí puede reabrirse/
 * interrumpirse cuando existe una incidencia real que impide completarla":
 * - el rider la reporta con motivo, comentario, GPS y hora (§14.1); al operador le llega una alerta urgente (§14.2, §25);
 * - el operador decide: sigue con la ruta, o "Interrumpir ruta" y buscar reemplazo con rondas nuevas (o asignar a mano),
 *   definiendo de dónde se recoge el paquete: donde está el rider (la custodia sigue con él) o el centro, si ya volvió
 *   (§14.2 puntos 3 a 5). El SLA sigue corriendo (§5);
 * - el reemplazo recibe el paquete y se registra el traspaso rider → rider con hora y ubicación (§14.3); "no se debe
 *   teletransportar el stock". Las entregas ya hechas no se tocan (§14.2 punto 7: hoy cada ruta es de una parada);
 * - el tramo cortado no se paga (§14): el rider no entregó, así que no tiene ganancia de esa orden; la tiene el reemplazo.
 */
class Incidents
{
    public function __construct(private LogisticsOrders $orders, private Alerts $alerts, private Departures $departures)
    {
    }

    /** §14.1: "Reportar incidencia de ruta". */
    public function report(LogisticsOrder $row, User $rider, string $type, ?string $comment, ?float $lat = null, ?float $lng = null): array
    {
        return DB::transaction(function () use ($row, $rider, $type, $comment, $lat, $lng) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ((int) $row->rider_id !== (int) $rider->id || !in_array($row->status, [LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT], true)) {
                return ['ok' => false, 'message' => 'Solo se reporta una incidencia de una orden tuya que está en camino.', 'code' => 409];
            }
            if (LogisticsIncident::where('logistics_order_id', $row->id)->whereIn('status', LogisticsIncident::ACTIVE)->exists()) {
                return ['ok' => false, 'message' => 'Esta ruta ya tiene una incidencia abierta: el operador la está viendo.', 'code' => 409];
            }
            $incident = LogisticsIncident::create([
                'logistics_order_id' => $row->id,
                'order_id' => $row->order_id,
                'center_id' => $row->center_id,
                'rider_id' => $rider->id,
                'type' => $type,
                'comment' => $comment,
                'lat' => $lat,
                'lng' => $lng,
                'reported_at' => now(),
                'status' => LogisticsIncident::OPEN,
            ]);
            $this->orders->event($row, 'incidencia', $row->status, $row->status, $rider->id, $comment,
                ['tipo' => $type, 'incidencia_id' => $incident->id, 'lat' => $lat, 'lng' => $lng]);
            $this->alerts->raise($row->center, 'incidencia', LogisticsAlert::CRITICAL,
                "{$this->label($row)}: {$rider->names} reporta una incidencia de ruta, \"{$incident->typeLabel()}\"" . ($comment ? ": {$comment}" : '')
                . '. Decide si sigue o si se interrumpe la ruta.', "incidencia:{$incident->id}", $row->order_id, $rider->id);

            return ['ok' => true, 'message' => 'Incidencia enviada: el operador te va a decir qué hacer.', 'code' => 200];
        });
    }

    /** El operador decide que el rider puede seguir con la ruta (por ejemplo, el bloqueo se despejó). */
    public function keepGoing(LogisticsIncident $incident, User $by, ?string $note = null): array
    {
        if ($incident->status !== LogisticsIncident::OPEN) {
            return ['ok' => false, 'message' => 'Esta incidencia ya se decidió.', 'code' => 409];
        }
        $incident->update(['status' => LogisticsIncident::CONTINUES, 'resolved_at' => now(), 'resolved_by' => $by->id, 'result' => $note ?: 'Sigue con la ruta.']);
        $row = $incident->logisticsOrder;
        $this->orders->event($row, 'incidencia_sigue', $row->status, $row->status, $by->id, $note, ['incidencia_id' => $incident->id]);
        $this->alerts->resolve("incidencia:{$incident->id}", $by->id);

        return ['ok' => true, 'message' => 'Listo: el rider sigue con la ruta.', 'code' => 200];
    }

    /**
     * §14.2 puntos 3 a 5: "Interrumpir ruta" y buscar reemplazo. pickup = rider: el paquete se recoge donde está el rider
     * (sigue bajo su custodia hasta el traspaso). pickup = almacen: ya volvió al centro; la custodia pasa al almacén ahora
     * y sale como cualquier orden (también por agencia, §19).
     */
    public function interrupt(LogisticsIncident $incident, User $by, string $pickup, ?string $note = null): array
    {
        if (!in_array($pickup, ['rider', 'almacen'], true)) {
            return ['ok' => false, 'message' => 'Elige de dónde se recoge el paquete.', 'code' => 422];
        }
        $out = DB::transaction(function () use ($incident, $by, $pickup, $note) {
            $incident = LogisticsIncident::whereKey($incident->id)->lockForUpdate()->first();
            $row = LogisticsOrder::whereKey($incident->logistics_order_id)->lockForUpdate()->first();
            if ($incident->status !== LogisticsIncident::OPEN) {
                return ['ok' => false, 'message' => 'Esta incidencia ya se decidió.', 'code' => 409];
            }
            if ((int) $row->rider_id !== (int) $incident->rider_id || !in_array($row->status, [LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT], true)) {
                return ['ok' => false, 'message' => 'La orden ya no está en camino con ese rider.', 'code' => 409];
            }
            $order = Order::whereKey($row->order_id)->lockForUpdate()->first();
            $now = now();
            // La novedad que hubiera queda cerrada: la ruta se interrumpe
            LogisticsNovelty::where('logistics_order_id', $row->id)->whereNull('resolved_at')
                ->update(['resolved_at' => $now, 'resolved_by' => $by->id, 'result' => 'Ruta interrumpida por una incidencia (§14).']);
            if ($pickup === 'almacen') {
                $this->departures->custody($row, $order, 'rider', $row->rider_id, 'almacen', $row->center?->warehouse_id, $by->id);
            }
            $this->orders->transition($row, LogisticsStatus::SEARCHING, 'ruta_interrumpida', $by->id, $note,
                ['incidencia_id' => $incident->id, 'recoge' => $pickup, 'rider_anterior' => $row->rider_id], [
                    'rider_id' => null,
                    'pickup_rider_id' => $pickup === 'rider' ? $row->rider_id : null,
                    'searching_started_at' => $now,
                    'rider_confirmed_at' => null,
                    // Con el paquete de vuelta en el centro, ya está listo y vuelve a salir de ahí
                    'ready_at' => $pickup === 'almacen' ? $now : null,
                    'departed_at' => $pickup === 'almacen' ? null : $row->departed_at,
                    'assigned_via' => null,
                    'assigned_by' => null,
                ]);
            $incident->update(['status' => $pickup === 'almacen' ? LogisticsIncident::CLOSED : LogisticsIncident::INTERRUPTED, 'pickup' => $pickup,
                'interrupted_at' => $now, 'interrupted_by' => $by->id] + ($pickup === 'almacen'
                    ? ['resolved_at' => $now, 'resolved_by' => $by->id, 'result' => 'Ruta interrumpida; el paquete volvió al centro.'] : []));
            // La orden ya no es de ese rider (sin pasar por los observadores: el estado de la orden no cambia)
            Order::whereKey($order->id)->update(['deliverer_id' => null]);

            return ['ok' => true, 'code' => 200, 'message' => $pickup === 'rider'
                ? 'Ruta interrumpida: se busca reemplazo, que recoge el paquete donde está el rider.'
                : 'Ruta interrumpida: el paquete está en el centro y se busca otro rider.'];
        });
        if ($out['ok']) {
            $this->alerts->resolve("incidencia:{$incident->id}", $by->id);
        }

        return $out;
    }

    /** §14.3: el reemplazo recibió el paquete del rider que tuvo la incidencia. Lo confirma el reemplazo o el operador. */
    public function transfer(LogisticsOrder $row, User $by, ?float $lat = null, ?float $lng = null): array
    {
        return DB::transaction(function () use ($row, $by, $lat, $lng) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if (!$row->pickup_rider_id || !$row->rider_id
                || !in_array($row->status, [LogisticsStatus::RIDER_CONFIRMED, LogisticsStatus::PREPARING, LogisticsStatus::READY], true)) {
                return ['ok' => false, 'message' => 'No hay un traspaso pendiente en esta orden.', 'code' => 409];
            }
            if ($by->role?->description === 'Repartidor' && (int) $by->id !== (int) $row->rider_id) {
                return ['ok' => false, 'message' => 'El traspaso lo confirma el rider que recibe el paquete.', 'code' => 403];
            }
            $order = Order::whereKey($row->order_id)->lockForUpdate()->first();
            $from = (int) $row->pickup_rider_id;
            $this->departures->custody($row, $order, 'rider', $from, 'rider', $row->rider_id, $by->id, $lat, $lng);
            $this->orders->transition($row, LogisticsStatus::EN_ROUTE, 'traspaso', $by->id, null, ['de' => $from, 'a' => $row->rider_id, 'lat' => $lat, 'lng' => $lng],
                ['pickup_rider_id' => null, 'departed_at' => now()]);
            LogisticsIncident::where('logistics_order_id', $row->id)->where('status', LogisticsIncident::INTERRUPTED)
                ->update(['status' => LogisticsIncident::CLOSED, 'replacement_rider_id' => $row->rider_id, 'transferred_at' => now(),
                    'resolved_at' => now(), 'resolved_by' => $by->id, 'result' => 'Traspaso al reemplazo.']);

            return ['ok' => true, 'message' => 'Traspaso confirmado: el paquete sigue con el reemplazo.', 'code' => 200];
        });
    }

    private function label(LogisticsOrder $row): string
    {
        return 'Orden ' . (Order::whereKey($row->order_id)->value('name') ?? "#{$row->order_id}");
    }
}
