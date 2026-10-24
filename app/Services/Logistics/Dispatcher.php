<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\DispatchOffer;
use App\Models\DispatchRound;
use App\Models\LogisticsAlert;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\RiderProfile;
use App\Models\User;
use App\Notifications\OrderAssignedNotification;
use App\Services\Agencies\AgencyRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Las rondas de asignación de una orden (§8, §19, §24; Fran R4). Con T contado desde que la orden entró a "Buscando
 * rider" (§8: "T=0:00"):
 * - R1 (0:00–1:30): a todos los elegibles que llegan dentro de los 45 min, y como mínimo a los 2 mejores (§7; audio de
 *   Fran del 23/10: "el sistema se lo envía a todos los riders que puedan cumplir con el tiempo");
 * - R2 (1:30–3:00): otra vez a los de R1 que siguen elegibles, más hasta 2 nuevos (§8, §8.3);
 * - T=3:00: alerta roja al operador para las llamadas (§8, §25); puede asignar a mano en cualquier momento (§8.4);
 * - T=5:00: pasa sola a la agencia de respaldo (R4: "que pase sola a Rapi2"); el operador puede pasarla antes (§19.1).
 * Gana el primer rider que confirma, con bloqueo en el servidor (§8.2, §33). Los tiempos salen de los parámetros (§31).
 * Las alarmas se mandan después de guardar, fuera del bloqueo; nunca llevan el destino (§8.1).
 */
class Dispatcher
{
    public function __construct(
        private Candidates $candidates,
        private LogisticsSettings $settings,
        private LogisticsOrders $orders,
        private PushSender $push,
        private Alerts $alerts,
        private AgencyRouter $router,
    ) {
    }

    /** Una pasada por las órdenes que buscan rider en el centro (la llama logistics:tick). */
    public function advanceCenter(int $centerId): void
    {
        LogisticsOrder::where('center_id', $centerId)->where('status', LogisticsStatus::SEARCHING)->orderBy('id')->pluck('id')
            ->each(function (int $id) {
                try {
                    $this->advance(LogisticsOrder::find($id));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }

    public function advance(?LogisticsOrder $row): void
    {
        if (!$row || $row->status !== LogisticsStatus::SEARCHING || !$row->searching_started_at) {
            return;
        }
        $settings = $this->settings->for($row->center);
        $now = now();
        $elapsed = (int) $row->searching_started_at->diffInSeconds($now);
        // Solo las rondas de esta búsqueda: una orden que vuelve a buscar rider (reingreso, reemplazo) empieza en R1 otra vez
        $cycle = fn () => $row->rounds()->where('started_at', '>=', $row->searching_started_at)->get()->keyBy('number');
        $rounds = $cycle();

        // Las rondas que vencieron sin confirmación
        foreach ($rounds as $round) {
            if (!$round->ended_at && $now->gte($round->expires_at)) {
                $this->closeRound($row, $round, 'sin_confirmar');
                $rounds = $cycle();
            }
        }
        if ($row->fresh()->status !== LogisticsStatus::SEARCHING) {
            return; // un rider confirmó mientras tanto
        }

        if ($elapsed >= (int) $settings['hard_cap_seconds']) {
            // §14: si el paquete sigue con el rider que tuvo la incidencia, la agencia no lo puede recoger en el centro (§19)
            if ($row->pickup_rider_id) {
                $this->alerts->raise($row->center, 'sin_reemplazo', LogisticsAlert::RED,
                    "{$this->label($row)}: nadie tomó el reemplazo y el paquete sigue con el rider de la incidencia. Asígnalo a mano o pide que lo traiga al centro.",
                    "ronda:{$row->id}:sin_reemplazo", $row->order_id);

                return;
            }
            $this->toAgency($row, null, 'minuto_5', 'Sin rider propio confirmado a los ' . round($settings['hard_cap_seconds'] / 60) . ' minutos (§8, R4).');

            return;
        }
        if ($elapsed >= (int) $settings['manual_calls_at_seconds']) {
            $this->alerts->raise($row->center, 'sin_rider', LogisticsAlert::RED,
                "{$this->label($row)}: sin rider a los " . round($settings['manual_calls_at_seconds'] / 60) . ' min. Llama a los riders y asígnala a mano; si no, pasa sola a la agencia.',
                "ronda:{$row->id}:sin_rider", $row->order_id);
        }

        if (!isset($rounds[1])) {
            $this->startRound($row, 1, $settings);
        } elseif (!isset($rounds[2]) && $rounds[1]->ended_at && $elapsed >= (int) $settings['round1_seconds']
            && $elapsed < (int) $settings['manual_calls_at_seconds']) {
            $this->startRound($row, 2, $settings, $rounds[1]);
        }
    }

    /** §8: arma la ronda con los elegibles del momento y le manda la alarma a cada uno. */
    private function startRound(LogisticsOrder $row, int $number, array $settings, ?DispatchRound $previous = null): void
    {
        // Las cuentas (y Google) van antes del bloqueo
        $result = $this->candidates->for($row);
        $eligible = collect($result['eligible']);
        if ($number === 1) {
            // Los que llegan en 45 min son los primeros de la lista: para una misma orden, el tiempo proyectado solo
            // cambia con el ETA al centro, que es el orden de la lista. Si son menos de N, se completa con los siguientes
            $chosen = $eligible->take(max($eligible->where('within_target', true)->count(), (int) $settings['round1_candidates']));
        } else {
            // §8.3: los de R1 vuelven a recibirla (si siguen elegibles), más hasta N nuevos
            $before = $previous->offers()->pluck('rider_id')->map(fn ($id) => (int) $id)->all();
            $again = $eligible->filter(fn ($l) => in_array($l['rider_id'], $before, true));
            $new = $eligible->reject(fn ($l) => in_array($l['rider_id'], $before, true))->take((int) $settings['round2_new_candidates']);
            $chosen = $again->concat($new)->values();
        }
        // El reloj es el de la tabla del §8 (R1 hasta T+1:30, R2 hasta T+3:00), contado desde que la orden empezó a buscar;
        // si la ronda arranca tarde, igual tiene al menos 30 s (decisión nuestra)
        $endsAt = $row->searching_started_at->copy()->addSeconds((int) $settings['round1_seconds'] + ($number === 2 ? (int) $settings['round2_seconds'] : 0));
        $expiresAt = max($endsAt, now()->addSeconds(30));
        $seconds = (int) ceil(now()->diffInSeconds($expiresAt));

        $created = DB::transaction(function () use ($row, $number, $chosen, $expiresAt) {
            $locked = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ($locked->status !== LogisticsStatus::SEARCHING
                || $locked->rounds()->where('number', $number)->where('started_at', '>=', $locked->searching_started_at)->exists()) {
                return null;
            }
            $now = now();
            $round = DispatchRound::create(['logistics_order_id' => $row->id, 'number' => $number, 'started_at' => $now, 'expires_at' => $expiresAt]);
            $offers = $chosen->map(fn ($l) => DispatchOffer::create([
                'round_id' => $round->id,
                'logistics_order_id' => $row->id,
                'rider_id' => $l['rider_id'],
                'rank' => $l['rank'],
                'eta_center_seconds' => $l['eta_center_seconds'],
                'projected_minutes' => $l['projected_minutes'],
                'sent_at' => $now,
                'expires_at' => $round->expires_at,
            ]));

            return [$round, $offers];
        });
        if (!$created) {
            return;
        }
        [$round, $offers] = $created;

        $this->candidates->record($row, $result, null, "ronda_{$number}");
        if ($offers->isEmpty()) {
            $this->alerts->raise($row->center, 'sin_elegibles', LogisticsAlert::WARNING,
                "{$this->label($row)}: ningún rider puede recibir la ronda {$number}" . ($result['warning'] ? " ({$result['warning']})" : '') . '. Puedes asignarla a mano o pasarla a la agencia.',
                "ronda:{$row->id}:sin_elegibles", $row->order_id);

            return;
        }

        $center = $row->center;
        $sent = 0;
        foreach ($offers as $offer) {
            $profile = RiderProfile::where('user_id', $offer->rider_id)->first();
            $res = $this->push->send($profile, [
                'type' => 'oferta',
                'offer_id' => $offer->id,
                'round' => $number,
                'expires_at' => $offer->expires_at->toIso8601String(),
                'seconds' => $seconds,
                'center' => (string) $center->name,
                'center_address' => (string) $center->address,
            ], $seconds);
            $offer->update(['push_ok' => $res['ok'], 'push_error' => $res['error'] ? mb_substr($res['error'], 0, 250) : null]);
            $sent += $res['ok'] ? 1 : 0;
        }
        if ($sent === 0) {
            $this->alerts->raise($center, 'alarma_fallo', LogisticsAlert::WARNING,
                "{$this->label($row)}: la alarma de la ronda {$number} no le llegó a ningún rider ({$offers->first()->push_error}).",
                "ronda:{$row->id}:alarma_fallo", $row->order_id);
        }
    }

    private function closeRound(LogisticsOrder $row, DispatchRound $round, string $result): void
    {
        $open = DB::transaction(function () use ($row, $round, $result) {
            // Siempre la orden primero, como confirm(): así un cierre y una confirmación al mismo tiempo no se traban
            LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            $round = DispatchRound::whereKey($round->id)->lockForUpdate()->first();
            if ($round->ended_at) {
                return collect();
            }
            $open = $round->offers()->whereNull('response')->get(['id', 'rider_id']);
            $round->offers()->whereNull('response')->update(['response' => $result === 'sin_confirmar' ? DispatchOffer::UNCONFIRMED : DispatchOffer::CANCELLED]);
            $round->update(['ended_at' => now(), 'result' => $result]);
            // §8: "registrar para cada uno 'No aceptó R1 / sin confirmación en ventana'"
            $this->orders->event($row, "ronda_{$round->number}_fin", $row->status, $row->status, null, $result,
                ['sin_confirmar' => $round->offers()->where('response', DispatchOffer::UNCONFIRMED)->pluck('rider_id')->all()]);

            return $open;
        });
        // El tono de la app dura 90 s: si la ronda vence antes, se apaga en el teléfono (audio de Fran del 23/10)
        foreach ($open as $offer) {
            if ($profile = RiderProfile::where('user_id', $offer->rider_id)->first()) {
                $this->push->send($profile, ['type' => 'vencida', 'offer_id' => $offer->id], 60);
            }
        }
    }

    /**
     * §8.2: el primer rider que confirma gana. Todo pasa con la fila de la orden bloqueada: un segundo que confirma en el
     * mismo instante encuentra la orden ya asignada.
     *
     * @return array{ok: bool, message: string, code: int, row?: LogisticsOrder}
     */
    public function confirm(DispatchOffer $offer, User $rider): array
    {
        if ((int) $offer->rider_id !== (int) $rider->id) {
            return ['ok' => false, 'message' => 'Esta oportunidad no es tuya.', 'code' => 403];
        }
        $outcome = DB::transaction(function () use ($offer, $rider) {
            $row = LogisticsOrder::whereKey($offer->logistics_order_id)->lockForUpdate()->first();
            $offer = DispatchOffer::whereKey($offer->id)->lockForUpdate()->first();
            if ($row->status !== LogisticsStatus::SEARCHING || $offer->response === DispatchOffer::COVERED) {
                return ['ok' => false, 'message' => 'Ya otro rider aceptó: asignación cubierta.', 'code' => 409];
            }
            if ($offer->response !== null || $offer->expires_at->isPast()) {
                // §33: aceptar una notificación vencida no reasigna nada
                return ['ok' => false, 'message' => 'Esta oportunidad ya venció.', 'code' => 409];
            }
            if ($problem = $this->riderProblem($rider->id, $row)) {
                return ['ok' => false, 'message' => $problem, 'code' => 409];
            }
            $offer->update(['response' => DispatchOffer::CONFIRMED, 'responded_at' => now()]);
            $offer->round->update(['ended_at' => now(), 'result' => 'confirmada']);
            $this->assignRider($row, $rider->id, 'ronda', null, null, ['offer_id' => $offer->id, 'ronda' => $offer->round->number]);

            return ['ok' => true, 'message' => 'Asignación confirmada: dirígete al centro.', 'code' => 200, 'row' => $row];
        });
        if ($outcome['ok']) {
            $this->notifyCovered($outcome['row'], $offer->id);
        }

        return $outcome;
    }

    /** §8.4: el operador asigna a un rider que le dijo que sí por teléfono (o en cualquier momento, §3). */
    public function assignManually(LogisticsOrder $row, int $riderId, User $by, ?string $comment): array
    {
        $outcome = DB::transaction(function () use ($row, $riderId, $by, $comment) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ($row->status !== LogisticsStatus::SEARCHING) {
                return ['ok' => false, 'message' => 'La orden ya no está buscando rider (' . LogisticsStatus::label($row->status) . ').', 'code' => 409];
            }
            $profile = RiderProfile::where('user_id', $riderId)->first();
            if (!$profile || (int) $profile->center_id !== (int) $row->center_id) {
                return ['ok' => false, 'message' => 'Ese rider no es de este centro.', 'code' => 422];
            }
            if (!$profile->is_active || $profile->suspended_at) {
                return ['ok' => false, 'message' => 'Ese rider está inactivo o suspendido.', 'code' => 422];
            }
            if ($problem = $this->riderProblem($riderId, $row)) {
                return ['ok' => false, 'message' => $problem, 'code' => 409];
            }
            $this->assignRider($row, $riderId, 'manual', $by->id, $comment, []);

            return ['ok' => true, 'message' => 'Orden asignada a mano.', 'code' => 200, 'row' => $row];
        });
        if ($outcome['ok']) {
            $this->notifyCovered($outcome['row'], null);
            $profile = RiderProfile::where('user_id', $riderId)->first();
            $this->push->send($profile, ['type' => 'asignada', 'logistics_order_id' => $row->id], 300);
        }

        return $outcome;
    }

    /**
     * §19: la agencia de respaldo, al minuto 5 (R4) o antes si el operador la fuerza (§19.1). Se elige entre las agencias
     * de la ciudad que trabajan, con el reparto de siempre; si todas están llenas no espera (Fran, 30/09 y 02/10). La
     * agencia recoge en el centro; el SLA sigue siendo el mismo (§33).
     */
    public function toAgency(LogisticsOrder $row, ?User $by, string $reason, ?string $note = null): array
    {
        $order = Order::find($row->order_id);
        [$agency, $why] = $this->router->pick($order, null, false, true, false);
        if (!$agency) {
            $this->alerts->raise($row->center, 'sin_agencia', LogisticsAlert::RED,
                "{$this->label($row)}: no hay agencia de respaldo que trabaje ahora ({$why}). Sigue buscando rider: asígnala a mano.",
                "ronda:{$row->id}:sin_agencia", $row->order_id);

            return ['ok' => false, 'message' => 'No hay agencia de respaldo disponible ahora.', 'code' => 422];
        }

        $outcome = DB::transaction(function () use ($row, $agency, $by, $reason, $note, $order) {
            $row = LogisticsOrder::whereKey($row->id)->lockForUpdate()->first();
            if ($row->status !== LogisticsStatus::SEARCHING) {
                return ['ok' => false, 'message' => 'La orden ya no está buscando rider (' . LogisticsStatus::label($row->status) . ').', 'code' => 409];
            }
            $this->closeOpen($row, DispatchOffer::CANCELLED);
            $order->agency_id = $agency->id;
            $order->save();
            $this->orders->transition($row, LogisticsStatus::AGENCY, 'a_agencia', $by?->id, $note, ['agencia' => $agency->names, 'motivo' => $reason], [
                'agency_id' => $agency->id,
                'overflow_reason' => $reason,
                'assigned_via' => 'agencia',
                'assigned_by' => $by?->id,
                'assignment_note' => $note !== null ? mb_substr($note, 0, 250) : null,
            ]);
            $this->alerts->resolve("ronda:{$row->id}:*", $by?->id);

            return ['ok' => true, 'message' => "Orden pasada a {$agency->names}: recoge en el centro.", 'code' => 200, 'row' => $row];
        });
        if ($outcome['ok']) {
            $this->notifyCovered($outcome['row'], null);
            try {
                $agency->notify(new OrderAssignedNotification($order, "Nueva orden asignada a tu agencia: {$order->number_label} (recoger en el centro {$row->center?->name})"));
            } catch (\Throwable $e) {
                Log::error('Error sending agency notification: ' . $e->getMessage());
            }
        }

        return $outcome;
    }

    /** La orden salió del centro mientras buscaba rider: se cierran las ofertas abiertas (y se le avisa a cada rider). */
    public function cancelOpenOffers(LogisticsOrder $row): void
    {
        $open = DispatchOffer::where('logistics_order_id', $row->id)->whereNull('response')->get();
        $this->closeOpen($row, DispatchOffer::CANCELLED);
        foreach ($open as $offer) {
            if ($profile = RiderProfile::where('user_id', $offer->rider_id)->first()) {
                $this->push->send($profile, ['type' => 'cancelada', 'offer_id' => $offer->id], 60);
            }
        }
    }

    /** Lo que impide que ese rider tome la orden: otra orden en curso (§6.1). */
    private function riderProblem(int $riderId, LogisticsOrder $row): ?string
    {
        RiderProfile::where('user_id', $riderId)->lockForUpdate()->first();
        $busy = LogisticsOrder::where('rider_id', $riderId)->where('id', '!=', $row->id)->whereIn('status', Candidates::BUSY)->exists();

        return $busy ? 'Ese rider ya tiene otra orden en curso.' : null;
    }

    private function assignRider(LogisticsOrder $row, int $riderId, string $via, ?int $byId, ?string $comment, array $data): void
    {
        $this->closeOpen($row, DispatchOffer::COVERED);
        Order::whereKey($row->order_id)->first()?->forceFill(['deliverer_id' => $riderId])->save();
        // §20.1: RIDER CONFIRMADO → PREPARANDO o LISTO; si el operador ya lo tenía listo, queda listo para que lo recoja
        $to = $row->ready_at ? LogisticsStatus::READY : LogisticsStatus::RIDER_CONFIRMED;
        $this->orders->transition($row, $to, $via === 'manual' ? 'asignada_a_mano' : 'confirmada',
            $byId ?? $riderId, $comment, $data + ['rider_id' => $riderId], [
                'rider_id' => $riderId,
                'rider_confirmed_at' => now(),
                'assigned_via' => $via,
                'assigned_by' => $byId,
                'assignment_note' => $comment !== null ? mb_substr($comment, 0, 250) : null,
            ]);
        $this->alerts->resolve("ronda:{$row->id}:*", $byId);
    }

    /** Cierra las rondas y ofertas abiertas de la orden con esa respuesta. */
    private function closeOpen(LogisticsOrder $row, string $response): void
    {
        DispatchOffer::where('logistics_order_id', $row->id)->whereNull('response')->update(['response' => $response, 'responded_at' => now()]);
        DispatchRound::where('logistics_order_id', $row->id)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'result' => $response === DispatchOffer::COVERED ? 'confirmada' : 'cancelada']);
    }

    /** §8.2 punto 4: a los demás de la ronda, "Ya otro rider aceptó / asignación cubierta". */
    private function notifyCovered(LogisticsOrder $row, ?int $winnerOfferId): void
    {
        $losers = DispatchOffer::where('logistics_order_id', $row->id)
            ->whereIn('response', [DispatchOffer::COVERED, DispatchOffer::CANCELLED])
            ->where('responded_at', '>=', now()->subMinute())
            ->when($winnerOfferId, fn ($q) => $q->where('id', '!=', $winnerOfferId))->get();
        foreach ($losers as $offer) {
            if ($profile = RiderProfile::where('user_id', $offer->rider_id)->first()) {
                $this->push->send($profile, ['type' => 'cubierta', 'offer_id' => $offer->id], 60);
            }
        }
    }

    private function label(LogisticsOrder $row): string
    {
        return 'Orden ' . (Order::whereKey($row->order_id)->value('name') ?? "#{$row->order_id}");
    }
}
