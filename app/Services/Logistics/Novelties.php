<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Constants\OrderStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsNovelty;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Las novedades del rider (§13). Llegan primero al operador, no a la vendedora: el operador insiste con el cliente,
 * coordina la llegada o aclara la referencia. Lo comercial (cambio de dirección, de producto, precio, cancelación, pago o
 * vuelto extraordinario) no lo resuelve él: lo escala, y la orden pasa a "Novedades" como hoy para que la vea la
 * vendedora (R3). Cuando la vendedora la soluciona ("Novedad Solucionada"), la orden vuelve a la calle.
 * Cada novedad guarda tipo, quién la reportó, cuándo, responsable, comentarios, escalado y resultado (§13).
 */
class Novelties
{
    public function __construct(private LogisticsOrders $orders, private Alerts $alerts)
    {
    }

    public function report(LogisticsOrder $row, User $rider, string $type, ?string $description, ?float $lat = null, ?float $lng = null): array
    {
        return DB::transaction(function () use ($row, $rider, $type, $description, $lat, $lng) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ((int) $row->rider_id !== (int) $rider->id || !in_array($row->status, [LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT], true)) {
                return ['ok' => false, 'message' => 'Solo se reporta una novedad de una orden tuya que está en camino.', 'code' => 409];
            }
            if (LogisticsNovelty::where('logistics_order_id', $row->id)->whereNull('resolved_at')->exists()) {
                return ['ok' => false, 'message' => 'Esta orden ya tiene una novedad abierta: el operador la está viendo.', 'code' => 409];
            }
            $novelty = LogisticsNovelty::create([
                'logistics_order_id' => $row->id,
                'order_id' => $row->order_id,
                'type' => $type,
                'description' => $description,
                'reported_by' => $rider->id,
                'opened_at' => now(),
                'owner' => 'operador',
                'lat' => $lat,
                'lng' => $lng,
            ]);
            $this->orders->transition($row, LogisticsStatus::INCIDENT, 'novedad', $rider->id, $description, ['tipo' => $type, 'novedad_id' => $novelty->id]);
            // §25: "Rider reporta novedad/incidencia → alerta inmediata"
            $this->alerts->raise($row->center, 'novedad', LogisticsAlert::RED,
                "{$this->label($row)}: {$rider->names} reporta \"{$novelty->typeLabel()}\"" . ($description ? ": {$description}" : '.'),
                "novedad:{$novelty->id}", $row->order_id, $rider->id);

            return ['ok' => true, 'message' => 'Novedad enviada al operador.', 'code' => 200, 'novelty' => $novelty];
        });
    }

    public function comment(LogisticsNovelty $novelty, User $by, string $text): LogisticsNovelty
    {
        $comments = $novelty->comments ?? [];
        $comments[] = ['at' => now()->toIso8601String(), 'user_id' => $by->id, 'name' => $by->names, 'text' => mb_substr($text, 0, 1000)];
        $novelty->update(['comments' => $comments]);

        return $novelty;
    }

    /** El operador la resolvió (por ejemplo, el cliente ya bajó): la orden sigue su ruta. Lo comercial no (§13). */
    public function resolve(LogisticsNovelty $novelty, User $by, string $result): array
    {
        if (LogisticsNovelty::TYPES[$novelty->type]['escalar'] ?? false) {
            return ['ok' => false, 'message' => 'Esta novedad es comercial: el operador no la resuelve por sí solo, tiene que escalarla a la vendedora (§13).', 'code' => 422];
        }

        return DB::transaction(function () use ($novelty, $by, $result) {
            $row = LogisticsOrder::whereKey($novelty->logistics_order_id)->lockForUpdate()->first();
            $novelty = LogisticsNovelty::whereKey($novelty->id)->lockForUpdate()->first();
            if ($novelty->resolved_at || $novelty->escalated_at) {
                return ['ok' => false, 'message' => $novelty->escalated_at ? 'La novedad ya está con la vendedora.' : 'La novedad ya está resuelta.', 'code' => 409];
            }
            $novelty->update(['resolved_at' => now(), 'resolved_by' => $by->id, 'result' => mb_substr($result, 0, 250)]);
            if ($row->status === LogisticsStatus::INCIDENT) {
                $this->orders->transition($row, LogisticsStatus::EN_ROUTE, 'novedad_resuelta', $by->id, $result, ['novedad_id' => $novelty->id]);
            }
            $this->alerts->resolve("novedad:{$novelty->id}", $by->id);

            return ['ok' => true, 'message' => 'Novedad resuelta: el rider sigue su ruta.', 'code' => 200];
        });
    }

    /** §13: "¿Escalar a vendedora? Sí". La orden pasa a "Novedades" con el tipo y el detalle, como hoy (R3). */
    public function escalate(LogisticsNovelty $novelty, User $by, ?string $comment): array
    {
        $out = DB::transaction(function () use ($novelty, $by, $comment) {
            $novelty = LogisticsNovelty::whereKey($novelty->id)->lockForUpdate()->first();
            if ($novelty->resolved_at || $novelty->escalated_at) {
                return ['ok' => false, 'message' => 'La novedad ya está resuelta o escalada.', 'code' => 409];
            }
            $novelty->update(['owner' => 'ventas', 'escalated_at' => now(), 'escalated_by' => $by->id]);
            if ($comment) {
                $this->comment($novelty, $by, $comment);
            }
            $order = Order::find($novelty->order_id);
            $order->novedad_type = $novelty->typeLabel();
            $order->novedad_description = trim(($novelty->description ?? '') . ($comment ? " (Operador: {$comment})" : '')) ?: null;
            $order->status_id = Status::where('description', OrderStatus::NOVEDADES)->value('id');
            $order->save();
            $this->alerts->resolve("novedad:{$novelty->id}", $by->id);

            return ['ok' => true, 'message' => 'Novedad escalada: la orden pasó a "Novedades" y la ve la vendedora.', 'code' => 200, 'order' => $order];
        });
        if ($out['ok']) {
            event(new \App\Events\OrderUpdated($out['order']));
            unset($out['order']);
        }

        return $out;
    }

    /** La vendedora la solucionó ("Novedad Solucionada"): se cierra la novedad y la orden vuelve a la calle. */
    public function solvedBySales(LogisticsOrder $row, ?int $actorId): void
    {
        $open = LogisticsNovelty::where('logistics_order_id', $row->id)->whereNull('resolved_at')->get();
        foreach ($open as $novelty) {
            $novelty->update(['resolved_at' => now(), 'resolved_by' => $actorId, 'result' => 'Solucionada por ventas']);
            $this->alerts->resolve("novedad:{$novelty->id}", $actorId);
        }
        if ($row->status === LogisticsStatus::INCIDENT) {
            // Una orden de la agencia de respaldo vuelve a la agencia; una del rider, a su ruta
            $back = $row->agency_id && !$row->rider_id ? LogisticsStatus::AGENCY : LogisticsStatus::EN_ROUTE;
            $this->orders->transition($row, $back, 'novedad_solucionada', $actorId, 'Novedad Solucionada por ventas');
        }
    }

    private function label(LogisticsOrder $row): string
    {
        return 'Orden ' . (Order::whereKey($row->order_id)->value('name') ?? "#{$row->order_id}");
    }
}
