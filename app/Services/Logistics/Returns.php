<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Constants\OrderStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Status;
use App\Models\User;
use App\Services\Inventory\DefectiveReview;
use App\Services\Inventory\OrderStock;
use App\Services\InventoryService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Los retornos al centro (§16; R5, R7). Una orden que no se entregó con la pieza fuera queda RETORNO PENDIENTE bajo
 * custodia del rider (LogisticsOrderObserver), y su stock no se mueve mientras tanto (OrderStock). El rider puede
 * terminar sus otras paradas y volver de forma natural; si viene a recoger otra ruta, primero entrega el retorno
 * (§16.1 punto 3, en Departures::depart). Al recibirlo el operador (§16.1 punto 4):
 * - si la orden sigue guardando su pieza (reprogramada, o la vendedora ya la volvió a mandar): la pieza queda apartada
 *   para la misma orden, sin revisión (R5: "Sí, apartada para la misma orden");
 * - si no (cancelada, rechazada, de vuelta a la vendedora…): entra al centro EN REVISIÓN, y solo administración la
 *   reingresa a disponible o la da de baja (§16.1 puntos 5 a 7, §16.2.2 puntos 7 y 8; §29 caso 14).
 * El regreso no se paga (§16, §18.3). Plazo: el mismo día, antes del cierre del centro (R7); al vencer, alerta (§16.1).
 */
class Returns
{
    public function __construct(
        private LogisticsOrders $orders,
        private Departures $departures,
        private Alerts $alerts,
        private OrderStock $stock,
        private DefectiveReview $review,
    ) {
    }

    /** §16.1 punto 4: el operador recibe físicamente el retorno. */
    public function receive(LogisticsOrder $row, User $by, ?string $note = null): array
    {
        $out = DB::transaction(function () use ($row, $by, $note) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ($row->status !== LogisticsStatus::RETURN_PENDING) {
                return ['ok' => false, 'message' => 'No hay un retorno pendiente: la orden está en "' . LogisticsStatus::label($row->status) . '".', 'code' => 409];
            }
            $order = Order::whereKey($row->order_id)->lockForUpdate()->first();
            $status = (string) Status::whereKey($order->status_id)->value('description');
            $this->departures->custody($row, $order, 'rider', $row->rider_id, 'almacen', $row->center?->warehouse_id, $by->id);
            $now = now();

            // Primero el estado logístico: con la pieza ya fuera de la custodia del rider, el stock se puede mover
            if (self::keepsPiece($order, $status)) {
                $this->orders->transition($row, LogisticsStatus::CLOSED, 'retorno_recibido', $by->id, $note,
                    ['estado_orden' => $status, 'destino' => 'apartada'], ['returned_at' => $now, 'closed_at' => $now]);
                $this->stock->sync($order);

                return ['ok' => true, 'message' => 'Retorno recibido: la pieza queda apartada para la misma orden.', 'code' => 200,
                    'reenter' => $status === OrderStatus::ASIGNAR_A_AGENCIA];
            }
            $row = $this->orders->transition($row, LogisticsStatus::IN_REVIEW, 'retorno_recibido', $by->id, $note,
                ['estado_orden' => $status, 'destino' => 'revision'], ['returned_at' => $now]);
            $pieces = $this->stock->returnToReview($order, $status);
            // Lo que entró a revisión, para que administración lo resuelva exactamente
            $this->orders->event($row, 'en_revision', LogisticsStatus::IN_REVIEW, LogisticsStatus::IN_REVIEW, $by->id, null, ['piezas' => $pieces]);

            return ['ok' => true, 'message' => 'Retorno recibido: la pieza queda en revisión hasta que administración la reingrese o la dé de baja.',
                'code' => 200, 'reenter' => false];
        });
        if ($out['ok']) {
            $this->alerts->resolve("retorno:{$row->id}:*", $by->id);
            // La vendedora la volvió a mandar mientras la pieza estaba fuera: con la pieza de vuelta, entra otra vez al centro
            if ($out['reenter'] && $row->center) {
                $this->orders->enter(Order::find($row->order_id), $row->center, $by->id);
            }
        }
        unset($out['reenter']);

        return $out;
    }

    /** §16.1 punto 7, §16.2.2 punto 8: administración reingresa la pieza a disponible (restock) o la da de baja (discard). */
    public function resolveReview(LogisticsOrder $row, User $by, string $action, ?string $note = null): array
    {
        if (!Shifts::isAdmin($by)) {
            return ['ok' => false, 'message' => 'Solo administración reingresa o da de baja un retorno (§16.1).', 'code' => 403];
        }
        try {
            $pieces = DB::transaction(function () use ($row, $by, $action, $note) {
                $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
                if ($row->status !== LogisticsStatus::IN_REVIEW) {
                    throw new \DomainException('Este retorno no está en revisión: está en "' . LogisticsStatus::label($row->status) . '".');
                }
                $pieces = $row->events()->where('action', 'en_revision')->latest('id')->first()?->data['piezas'] ?? [];
                $label = 'Retorno de la orden ' . (Order::whereKey($row->order_id)->value('name') ?? "#{$row->order_id}") . '.';
                foreach ($pieces as $p) {
                    $variant = $p['variant_id'] ? ProductVariant::find($p['variant_id']) : null;
                    $res = $this->review->resolve((int) $p['warehouse_id'], (int) $p['product_id'], $variant, (int) $p['quantity'], $action,
                        trim($label . ' ' . ($note ?? '')));
                    if (!$res['ok']) {
                        throw new \DomainException($res['message']);
                    }
                }
                $now = now();
                $this->orders->transition($row, LogisticsStatus::CLOSED, $action === 'restock' ? 'reingresada' : 'dada_de_baja', $by->id, $note,
                    ['piezas' => $pieces], ['review_resolved_at' => $now, 'closed_at' => $now]);

                return $pieces;
            });
        } catch (\DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'code' => 422];
        }
        // Lo vendible subió: las órdenes en "Sin Stock" por estos productos pueden seguir
        if ($action === 'restock') {
            foreach ($pieces as $p) {
                app(InventoryService::class)->recheckStock((int) $p['product_id'], (int) $p['warehouse_id'], false);
            }
        }

        return ['ok' => true, 'message' => $action === 'restock' ? 'Listo: la pieza vuelve a estar disponible.' : 'Listo: la pieza se dio de baja.', 'code' => 200];
    }

    /** R7: el mismo día, antes del cierre del centro. Si ya había cerrado cuando quedó pendiente, hasta el final del día. */
    public static function deadline(LogisticsOrder $row, LogisticsCenter $center): ?CarbonInterface
    {
        if (!$row->return_pending_at) {
            return null;
        }
        $day = $row->return_pending_at->copy();
        $hours = Centers::hoursIn($center->opening_hours ?? Centers::DEFAULT_HOURS, $day);
        $close = $hours ? $day->copy()->setTimeFromTimeString($hours[1]) : null;

        return $close && $close->gt($row->return_pending_at) ? $close : $day->copy()->endOfDay();
    }

    public static function isOverdue(LogisticsOrder $row, LogisticsCenter $center, ?CarbonInterface $at = null): bool
    {
        $deadline = self::deadline($row, $center);

        return $row->status === LogisticsStatus::RETURN_PENDING && $deadline !== null && ($at ?? now())->gt($deadline);
    }

    /** §16.1: "generar alerta de vencimiento". La corre logistics:tick. */
    public function checkOverdue(LogisticsCenter $center): int
    {
        $count = 0;
        LogisticsOrder::with(['order:id,name', 'rider:id,names'])->where('center_id', $center->id)
            ->where('status', LogisticsStatus::RETURN_PENDING)->get()
            ->filter(fn (LogisticsOrder $row) => self::isOverdue($row, $center))
            ->each(function (LogisticsOrder $row) use ($center, &$count) {
                $this->alerts->raise($center, 'retorno_vencido', LogisticsAlert::WARNING,
                    "Orden {$row->order?->name}: venció el plazo del retorno (" . self::deadline($row, $center)->format('d/m H:i') . '). '
                    . ($row->rider ? "Pídele a {$row->rider->names} que lo traiga." : 'Pide que lo traigan.'),
                    "retorno:{$row->id}:vencido", $row->order_id, $row->rider_id);
                $count++;
            });

        return $count;
    }

    /** R5: la pieza sigue apartada si el estado de la orden la guarda (la misma regla del stock: no es de las que devuelven). */
    public static function keepsPiece(Order $order, string $status): bool
    {
        return !$order->is_return && !in_array($status, OrderStatus::RETURN_STATUSES, true);
    }
}
