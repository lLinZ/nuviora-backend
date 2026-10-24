<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\LogisticsShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El turno del operador (§4.2, §31.1). "Iniciar turno" abre el centro para despacho y suelta lo que esperaba ventana;
 * hay un solo operador de turno a la vez (§31: "Operadores simultáneos: 1"). Al cerrar, el sistema muestra lo pendiente:
 * con pendientes hace falta un motivo, que queda a la vista del siguiente (§31.1: "Cierre limpio" o "Cerrar con
 * pendientes/traspaso").
 */
class Shifts
{
    public function __construct(private LogisticsOrders $orders, private Alerts $alerts)
    {
    }

    public function start(LogisticsCenter $center, User $user): LogisticsShift
    {
        if (!$this->isOperatorOf($user, $center) && !self::isAdmin($user)) {
            throw ValidationException::withMessages(['turno' => 'No eres operador de este centro.']);
        }

        $shift = DB::transaction(function () use ($center, $user) {
            LogisticsCenter::whereKey($center->id)->lockForUpdate()->first();
            $open = LogisticsShift::with('user')->where('center_id', $center->id)->whereNull('ended_at')->first();
            if ($open) {
                throw ValidationException::withMessages(['turno' => "Ya hay un turno abierto de {$open->user?->names}, desde las {$open->started_at->format('H:i')}."]);
            }

            // §4.1: queda quién estaba programado, aunque abra otro
            $scheduled = app(OperatorCalendar::class)->on($center, now())['user_id'];

            return LogisticsShift::create(['center_id' => $center->id, 'user_id' => $user->id, 'scheduled_user_id' => $scheduled, 'started_at' => now()]);
        });

        $this->alerts->resolve("sin_operador:{$center->id}:*", $user->id);
        $this->orders->releaseWaiting($center, $user->id);

        return $shift;
    }

    /** Lo que queda abierto en el centro (§31.1). En la semana 1: órdenes en curso y alertas abiertas. */
    public function pending(LogisticsCenter $center): array
    {
        $byStatus = LogisticsOrder::where('center_id', $center->id)
            ->whereNotIn('status', [...LogisticsStatus::FINISHED, LogisticsStatus::PENDING_WINDOW])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $items = [];
        foreach ($byStatus as $status => $total) {
            $items[] = ['tipo' => 'ordenes', 'estado' => $status, 'texto' => LogisticsStatus::label($status), 'total' => (int) $total];
        }
        // §31.1: "Efectivo esperado vs recibido por rider" (§17 punto 6: al cierre, todos en USD 0)
        $holding = array_filter(app(RiderCash::class)->balances(\App\Models\RiderProfile::where('center_id', $center->id)->pluck('user_id')),
            fn ($b) => abs($b['usd']) >= 0.01 || abs($b['ves']) >= 0.01);
        if ($holding) {
            $items[] = ['tipo' => 'efectivo', 'texto' => 'Riders con efectivo sin entregar (USD ' . number_format(array_sum(array_column($holding, 'total_usd')), 2, ',', '.') . ')',
                'total' => count($holding)];
        }
        // §31.1: "Pagos de riders pendientes" (§18.5: el cierre diario de honorarios)
        $unpaid = array_filter(app(RiderPay::class)->day($center, now()->toDateString())['riders'], fn ($r) => $r['pending'] >= 0.01);
        if ($unpaid) {
            $items[] = ['tipo' => 'pagos', 'texto' => 'Riders sin cobrar sus carreras de hoy (USD ' . number_format(array_sum(array_column($unpaid, 'pending')), 2, ',', '.') . ')',
                'total' => count($unpaid)];
        }
        $alerts = LogisticsAlert::where('center_id', $center->id)->whereNull('resolved_at')->count();
        if ($alerts > 0) {
            $items[] = ['tipo' => 'alertas', 'texto' => 'Alertas abiertas', 'total' => $alerts];
        }

        return $items;
    }

    public function close(LogisticsCenter $center, User $user, ?string $reason = null): LogisticsShift
    {
        $shift = LogisticsShift::where('center_id', $center->id)->whereNull('ended_at')->first();
        if (!$shift) {
            throw ValidationException::withMessages(['turno' => 'No hay un turno abierto en este centro.']);
        }
        if ((int) $shift->user_id !== (int) $user->id && !self::isAdmin($user) && !$this->isOperatorOf($user, $center)) {
            throw ValidationException::withMessages(['turno' => 'Solo un operador del centro puede cerrar el turno.']);
        }

        $pending = $this->pending($center);
        $reason = $reason !== null ? trim($reason) : null;
        if ($pending !== [] && !$reason) {
            throw ValidationException::withMessages(['motivo' => 'Quedan pendientes: para cerrar así hace falta un motivo (§31.1).']);
        }

        $shift->update([
            'ended_at' => now(),
            'closed_by' => $user->id,
            'close_type' => $pending === [] ? LogisticsShift::CLOSE_CLEAN : LogisticsShift::CLOSE_WITH_PENDING,
            'close_reason' => $reason ?: null,
            'pending_at_close' => $pending ?: null,
        ]);

        return $shift;
    }

    public function isOperatorOf(User $user, LogisticsCenter $center): bool
    {
        return $center->operators()->where('users.id', $user->id)->exists();
    }

    public static function isAdmin(User $user): bool
    {
        return in_array(strtolower((string) $user->role?->description), ['admin', 'master'], true);
    }
}
