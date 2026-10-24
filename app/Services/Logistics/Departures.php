<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Constants\OrderStatus;
use App\Events\OrderUpdated;
use App\Models\LogisticsCustodyEvent;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Del almacén a la calle (§9.2, §11, §16.2.2, §19):
 * - el operador prepara el paquete (producto en su caja → bolsa negra → etiqueta) y lo marca "Listo para despacho"; lo
 *   puede tener listo mientras todavía se busca rider (§32 punto 5: "prepara paquetes mientras corre la asignación");
 * - "Confirmar salida" es el punto de bloqueo: el producto pasa a custodia del rider, la ruta queda EN VÍA y la orden
 *   pasa a "En ruta" como siempre (§11; R3: el cliente recibe los mismos mensajes);
 * - a la agencia de respaldo el operador le entrega el paquete en el centro: pasa a custodia de la agencia (§16.2.2.4),
 *   y la agencia sigue la orden en su pantalla, como hoy.
 * Sin stock apartado para la orden, no sale (§16.2: "un pedido no puede salir si la cantidad… no está disponible o
 * reservada"). Ruta de una parada en la semana 1; las dobles vienen después (§10).
 */
class Departures
{
    public const PREPARABLE = [LogisticsStatus::RIDER_CONFIRMED];
    public const READYABLE = [LogisticsStatus::SEARCHING, LogisticsStatus::RIDER_CONFIRMED, LogisticsStatus::PREPARING];
    public const DEPARTABLE = [LogisticsStatus::RIDER_CONFIRMED, LogisticsStatus::PREPARING, LogisticsStatus::READY];

    public function __construct(private LogisticsOrders $orders)
    {
    }

    public function prepare(LogisticsOrder $row, User $by): array
    {
        return DB::transaction(function () use ($row, $by) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if (!in_array($row->status, self::PREPARABLE, true)) {
                return $this->refuse($row, 'preparar');
            }
            $this->orders->transition($row, LogisticsStatus::PREPARING, 'preparando', $by->id);

            return ['ok' => true, 'message' => 'Preparando el paquete.', 'code' => 200];
        });
    }

    /** §9.2: "marcar 'Listo para despacho'". Si todavía no hay rider, queda listo y pasa a LISTO al confirmarse uno. */
    public function ready(LogisticsOrder $row, User $by): array
    {
        return DB::transaction(function () use ($row, $by) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if (!in_array($row->status, self::READYABLE, true)) {
                return $this->refuse($row, 'marcar como listo');
            }
            $to = $row->status === LogisticsStatus::SEARCHING ? LogisticsStatus::SEARCHING : LogisticsStatus::READY;
            $this->orders->transition($row, $to, 'listo_despacho', $by->id, null, [], ['ready_at' => now()]);

            return ['ok' => true, 'message' => $to === LogisticsStatus::READY ? 'Paquete listo para despacho.' : 'Paquete listo: sale en cuanto un rider confirme.', 'code' => 200];
        });
    }

    /** §11: "Confirmar salida". */
    public function depart(LogisticsOrder $row, User $by): array
    {
        $out = DB::transaction(function () use ($row, $by) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if (!in_array($row->status, self::DEPARTABLE, true) || !$row->rider_id) {
                return $this->refuse($row, 'confirmar la salida');
            }
            // §14.3: el paquete no está en el centro sino con el rider que tuvo la incidencia
            if ($row->pickup_rider_id) {
                return ['ok' => false, 'message' => 'El paquete está con el rider que tuvo la incidencia: confirma el traspaso cuando el reemplazo lo reciba (§14.3).', 'code' => 409];
            }
            // §16.1 punto 3: "debe entregar primero el retorno pendiente antes de recibir nuevos paquetes"
            $pendingReturn = LogisticsOrder::with('order:id,name')->where('rider_id', $row->rider_id)
                ->where('status', LogisticsStatus::RETURN_PENDING)->first();
            if ($pendingReturn) {
                return ['ok' => false, 'message' => "Primero recibe el retorno pendiente de este rider (orden {$pendingReturn->order?->name}): "
                    . 'no puede salir con paquetes nuevos sin entregarlo (§16.1).', 'code' => 409];
            }
            // §17 punto 2 y §6.1: con USD 60 o más, entrega el efectivo antes de llevarse otra ruta
            $cash = app(RiderCash::class);
            if ($row->center && $cash->overLimit((int) $row->rider_id, $row->center)) {
                return ['ok' => false, 'message' => 'Primero recibe el efectivo del rider: lleva USD ' . number_format($cash->balance((int) $row->rider_id)['total_usd'], 2, ',', '.')
                    . ' y el tope es USD ' . number_format($cash->limit($row->center), 0) . ' (§17).', 'code' => 409];
            }
            $order = Order::whereKey($row->order_id)->lockForUpdate()->first();
            if (!$order->isStockDeducted()) {
                return ['ok' => false, 'message' => 'La orden no tiene su producto apartado en el centro: no puede salir (§16.2).', 'code' => 422];
            }

            $now = now();
            $this->custody($row, $order, 'almacen', $row->center?->warehouse_id, 'rider', $row->rider_id, $by->id);
            $this->orders->transition($row, LogisticsStatus::EN_ROUTE, 'salida', $by->id, null, ['rider_id' => $row->rider_id],
                ['departed_at' => $now, 'ready_at' => $row->ready_at ?? $now]);

            // La orden pasa a "En ruta" como cuando lo marca una agencia (was_shipped y shipped_at), sin agencia (§11)
            $order->status_id = Status::where('description', OrderStatus::EN_RUTA)->value('id');
            $order->was_shipped = true;
            $order->shipped_at = $now;
            $order->save();

            return ['ok' => true, 'message' => 'Salida confirmada: la ruta queda cerrada.', 'code' => 200, 'order' => $order];
        });
        if ($out['ok']) {
            event(new OrderUpdated($out['order']));
            unset($out['order']);
        }

        return $out;
    }

    /** §19: el operador le entrega el paquete a la agencia de respaldo, en el centro. */
    public function handToAgency(LogisticsOrder $row, User $by): array
    {
        return DB::transaction(function () use ($row, $by) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ($row->status !== LogisticsStatus::AGENCY || !$row->agency_id) {
                return $this->refuse($row, 'entregar a la agencia');
            }
            if (LogisticsCustodyEvent::where('logistics_order_id', $row->id)->where('to_type', 'agencia')->exists()) {
                return ['ok' => false, 'message' => 'Ese paquete ya se le entregó a la agencia.', 'code' => 409];
            }
            $order = Order::find($row->order_id);
            $this->custody($row, $order, 'almacen', $row->center?->warehouse_id, 'agencia', $row->agency_id, $by->id);
            $this->orders->transition($row, LogisticsStatus::AGENCY, 'entregada_a_agencia', $by->id, null, [], ['departed_at' => now()]);

            return ['ok' => true, 'message' => 'Paquete entregado a la agencia.', 'code' => 200];
        });
    }

    public function custody(LogisticsOrder $row, Order $order, string $fromType, ?int $fromId, string $toType, ?int $toId, ?int $by,
        ?float $lat = null, ?float $lng = null): LogisticsCustodyEvent
    {
        return LogisticsCustodyEvent::create([
            'logistics_order_id' => $row->id,
            'order_id' => $order->id,
            'from_type' => $fromType,
            'from_id' => $fromId,
            'to_type' => $toType,
            'to_id' => $toId,
            'items' => $order->products()->get()->map(fn ($p) => [
                'product_id' => $p->product_id, 'variant_id' => $p->variant_id, 'quantity' => (int) $p->quantity, 'name' => $p->showable_name ?: $p->name ?: $p->title,
            ])->values()->all(),
            'lat' => $lat,
            'lng' => $lng,
            'confirmed_by' => $by,
            'created_at' => now(),
        ]);
    }

    private function refuse(LogisticsOrder $row, string $what): array
    {
        return ['ok' => false, 'message' => "No se puede {$what}: la orden está en \"" . LogisticsStatus::label($row->status) . '".', 'code' => 409];
    }
}
