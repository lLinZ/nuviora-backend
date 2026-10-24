<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderEvent;
use App\Models\Order;
use App\Models\OrderActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * La entrada de una orden a un centro y sus cambios de estado logístico (§5, §20.1, §26).
 * - Entra cuando la vendedora la pasa a "Asignar a agencia" en una ciudad con centro activo (R3).
 * - Si el centro puede soltarla (abierto, con operador y antes del cutoff), arranca el SLA y queda "Buscando rider";
 *   si no, "Pendiente de ventana" hasta que se pueda (§4.2 punto 5, §5: el SLA "no comienza mientras el centro está
 *   cerrado o sin operador").
 * - Cada cambio deja su evento, con quién, de qué estado a cuál y por qué (§26).
 */
class LogisticsOrders
{
    public function __construct(private Centers $centers)
    {
    }

    public function forOrder(Order|int $order): ?LogisticsOrder
    {
        return LogisticsOrder::where('order_id', $order instanceof Order ? $order->id : $order)->first();
    }

    /** La fila logística que sigue en curso (no entregada ni cerrada). */
    public function active(Order|int $order): ?LogisticsOrder
    {
        $row = $this->forOrder($order);

        return $row && !$row->isFinished() ? $row : null;
    }

    public function enter(Order $order, LogisticsCenter $center, ?int $actorId = null): LogisticsOrder
    {
        return DB::transaction(function () use ($order, $center, $actorId) {
            $row = LogisticsOrder::where('order_id', $order->id)->lockForUpdate()->first();
            if ($row && !$row->isFinished()) {
                return $row;
            }

            $reentry = $row !== null;
            $previous = $row?->only(['status', 'sla_started_at', 'closed_at']);
            $row ??= new LogisticsOrder(['order_id' => $order->id]);
            // Una orden que vuelve (por ejemplo, reprogramada para otro día) empieza otro ciclo: su SLA arranca de nuevo
            // al soltarse. El ciclo anterior queda en los eventos.
            $row->fill([
                'center_id' => $center->id,
                'status' => LogisticsStatus::PENDING_WINDOW,
                'rider_id' => null,
                'pickup_rider_id' => null,
                'agency_id' => null,
                'overflow_reason' => null,
                'released_at' => null,
                'sla_started_at' => null,
                'searching_started_at' => null,
                'rider_confirmed_at' => null,
                'ready_at' => null,
                'departed_at' => null,
                'delivered_at' => null,
                'return_pending_at' => null,
                'returned_at' => null,
                'review_resolved_at' => null,
                'closed_at' => null,
            ])->save();
            $this->event($row, $reentry ? 'reingreso' : 'entrada', $previous['status'] ?? null, LogisticsStatus::PENDING_WINDOW,
                $actorId, null, $reentry ? ['ciclo_anterior' => $previous] : []);
            $this->activity($order, "Entró al centro logístico {$center->name}.", $actorId);

            if ($this->centers->canRelease($center)) {
                $this->release($row, $actorId);
            }

            return $row->fresh();
        });
    }

    /** §5: la orden queda asignada a logística y arranca su SLA; empieza la búsqueda de rider (§8). */
    public function release(LogisticsOrder $row, ?int $actorId = null): LogisticsOrder
    {
        if ($row->status !== LogisticsStatus::PENDING_WINDOW) {
            return $row;
        }
        $now = now();

        return $this->transition($row, LogisticsStatus::SEARCHING, 'liberada', $actorId, null, [], [
            'released_at' => $now,
            'sla_started_at' => $now,
            'searching_started_at' => $now,
        ]);
    }

    /** Suelta las que esperaban ventana, de la más vieja a la más nueva, si el centro ya puede (§4.2 punto 5). */
    public function releaseWaiting(LogisticsCenter $center, ?int $actorId = null): int
    {
        if (!$this->centers->canRelease($center)) {
            return 0;
        }
        $count = 0;
        LogisticsOrder::where('center_id', $center->id)->where('status', LogisticsStatus::PENDING_WINDOW)
            ->orderBy('id')->get()
            ->each(function (LogisticsOrder $row) use ($actorId, &$count) {
                $this->release($row, $actorId);
                $count++;
            });

        return $count;
    }

    /** La orden salió del centro sin entregarse (cancelada, devuelta a la vendedora…). */
    public function close(LogisticsOrder $row, string $reason, ?int $actorId = null): LogisticsOrder
    {
        $wasSearching = $row->status === LogisticsStatus::SEARCHING;
        $row = $this->transition($row, LogisticsStatus::CLOSED, 'cerrada', $actorId, $reason, [], ['closed_at' => now()]);
        if ($wasSearching) {
            app(Dispatcher::class)->cancelOpenOffers($row);
            app(Alerts::class)->resolve("ronda:{$row->id}:*", $actorId);
        }

        return $row;
    }

    public function transition(LogisticsOrder $row, string $to, string $action, ?int $actorId = null, ?string $reason = null,
        array $data = [], array $attributes = []): LogisticsOrder
    {
        $from = $row->status;
        $row->fill(['status' => $to] + $attributes)->save();
        $this->event($row, $action, $from, $to, $actorId, $reason, $data);

        return $row;
    }

    public function event(LogisticsOrder $row, string $action, ?string $from, ?string $to, ?int $actorId = null,
        ?string $reason = null, array $data = []): LogisticsOrderEvent
    {
        return LogisticsOrderEvent::create([
            'logistics_order_id' => $row->id,
            'actor_id' => $actorId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason !== null ? mb_substr($reason, 0, 250) : null,
            'data' => $data ?: null,
            'created_at' => now(),
        ]);
    }

    private function activity(Order $order, string $description, ?int $actorId): void
    {
        try {
            OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => $actorId,
                'action' => 'logistica',
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
