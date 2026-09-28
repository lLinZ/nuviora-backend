<?php

namespace App\Services\Agencies;

use App\Constants\OrderStatus;
use App\Models\AgencyTrip;
use App\Models\Earning;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Carreras de las agencias (tarea 5, Fran §4-6): se paga cada intento de entrega, con la tarifa de la
 * agencia, y cuenta en la semana en que se hizo.
 *  - Pasar a "En ruta" abre una carrera. El siguiente resultado (Entregado, Novedades, Rechazado,
 *    Cancelado…) la cierra. Si la orden vuelve a un estado previo sin haber salido, se anula.
 *  - De "Novedad Solucionada" la agencia va otra vez y marca Entregado o Novedades sin pasar por
 *    "En ruta": eso también es una carrera (re-entrega), que se abre y se cierra a la vez.
 *  - Un cambio (entregar la pieza buena y retirar la defectuosa) es una sola carrera, de tipo cambio.
 * Cada carrera tiene su ganancia (earnings, role_type agencia) con la fecha de la carrera.
 */
class AgencyTrips
{
    private const BEFORE_ROUTE = [OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::ASIGNAR_REPARTIDOR];
    private const RESULTS = [
        OrderStatus::ENTREGADO => 'entregado',
        OrderStatus::NOVEDADES => 'novedad',
        OrderStatus::RECHAZADO => 'rechazado',
        OrderStatus::CANCELADO => 'cancelado',
    ];

    public function onStatusChange(Order $order, ?int $fromId, int $toId): ?AgencyTrip
    {
        $names = Status::whereIn('id', array_filter([$fromId, $toId]))->pluck('description', 'id');
        $from = $fromId ? ($names[$fromId] ?? null) : null;
        $to = $names[$toId] ?? null;

        $open = AgencyTrip::where('order_id', $order->id)->whereNull('closed_at')->whereNull('voided_at')->latest('id')->first();

        if ($to === OrderStatus::EN_RUTA) {
            return $open ?? $this->open($order);
        }
        if ($open) {
            return in_array($to, self::BEFORE_ROUTE, true)
                ? $this->void($open, Auth::id(), "La orden volvió a \"{$to}\" sin haber salido")
                : $this->close($open, $to, $toId);
        }
        if ($from === OrderStatus::NOVEDAD_SOLUCIONADA && in_array($to, [OrderStatus::ENTREGADO, OrderStatus::NOVEDADES], true)) {
            $trip = $this->open($order);

            return $trip ? $this->close($trip, $to, $toId) : null;
        }

        return null;
    }

    /** Anula una carrera (error de estado o carrera que no se hizo): deja de pagarse, pero queda a la vista. */
    public function void(AgencyTrip $trip, ?int $userId, string $reason): AgencyTrip
    {
        DB::transaction(function () use ($trip, $userId, $reason) {
            $earningId = $trip->earning_id;
            $trip->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason, 'earning_id' => null]);
            if ($earningId) {
                Earning::whereKey($earningId)->delete();
            }
        });
        $this->activity($trip, "Carrera anulada ({$reason}). Ya no se le paga a la agencia.");

        return $trip;
    }

    /** Tarifa de la agencia; si no tiene, la de la ciudad de la orden. */
    public function price(Order $order, User $agency): float
    {
        return (float) $agency->delivery_cost > 0 ? (float) $agency->delivery_cost : (float) ($order->delivery_cost ?? 0);
    }

    private function open(Order $order): ?AgencyTrip
    {
        $agency = $order->agency_id ? User::find($order->agency_id) : null;
        if (!$agency) {
            return null;
        }

        // Si ya se le pagó un intento de esta orden (también las ganancias de antes de este registro), es re-entrega
        $hadAttempt = Earning::where('order_id', $order->id)->where('role_type', 'agencia')->where('user_id', $agency->id)->exists();
        $type = $order->is_exchange ? 'cambio' : ($hadAttempt ? 'reentrega' : 'normal');
        $price = round($this->price($order, $agency), 2);

        $trip = DB::transaction(function () use ($order, $agency, $type, $price) {
            $earning = Earning::create([
                'order_id' => $order->id,
                'user_id' => $agency->id,
                'role_type' => 'agencia',
                'amount_usd' => $price,
                'currency' => 'USD',
                'rate' => 1,
                'earning_date' => now()->toDateString(),
            ]);

            return AgencyTrip::create([
                'order_id' => $order->id,
                'agency_id' => $agency->id,
                'deliverer_id' => $order->deliverer_id,
                'type' => $type,
                'price_usd' => $price,
                'trip_date' => now()->toDateString(),
                'started_at' => now(),
                'earning_id' => $earning->id,
            ]);
        });
        $this->activity($trip, 'Carrera de ' . strtolower(AgencyTrip::TYPES[$type]) . " para {$agency->names}: $" . number_format($price, 2) . '.');

        return $trip;
    }

    private function close(AgencyTrip $trip, ?string $to, int $toId): AgencyTrip
    {
        $trip->update([
            'result' => self::RESULTS[$to] ?? 'otro',
            'result_status_id' => $toId,
            'closed_at' => now(),
        ]);

        return $trip;
    }

    private function activity(AgencyTrip $trip, string $description): void
    {
        try {
            OrderActivityLog::create([
                'order_id' => $trip->order_id,
                'user_id' => Auth::id(),
                'action' => 'agency_trip',
                'description' => $description,
                'properties' => ['trip_id' => $trip->id],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
