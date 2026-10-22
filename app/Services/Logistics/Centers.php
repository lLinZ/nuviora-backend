<?php

namespace App\Services\Logistics;

use App\Models\City;
use App\Models\LogisticsCenter;
use App\Models\Order;
use App\Services\Agencies\AgencyRouter;
use Carbon\CarbonInterface;

/**
 * Cuándo un centro logístico puede despachar (§4.2): tiene que estar activo, dentro de su horario (con las excepciones
 * por fecha) y con un operador que haya iniciado turno. "Sin operador físicamente presente no puede despacharse ni por
 * riders propios ni por agencia" (§4.2, §28). Las órdenes nuevas se sueltan solo hasta el cutoff.
 */
class Centers
{
    public const INACTIVE = 'inactivo';
    public const CLOSED = 'cerrado';
    public const NO_OPERATOR = 'sin_operador';
    public const OPEN = 'abierto';

    public const STATE_LABELS = [
        self::INACTIVE => 'Apagado',
        self::CLOSED => 'Cerrado',
        self::NO_OPERATOR => 'Sin operador',
        self::OPEN => 'Abierto para despacho',
    ];

    /** Horario por defecto de un centro nuevo (§4, §31): lunes a sábado de 08:00 a 19:00; domingo cerrado. */
    public const DEFAULT_HOURS = [
        '1' => ['08:00', '19:00'], '2' => ['08:00', '19:00'], '3' => ['08:00', '19:00'],
        '4' => ['08:00', '19:00'], '5' => ['08:00', '19:00'], '6' => ['08:00', '19:00'], '7' => null,
    ];

    public function __construct(private AgencyRouter $router)
    {
    }

    /** El centro activo de la ciudad, o null: entonces la ciudad sigue con sus agencias, como hoy. */
    public function forCity(?int $cityId): ?LogisticsCenter
    {
        return $cityId ? LogisticsCenter::where('city_id', $cityId)->where('is_active', true)->first() : null;
    }

    public function forOrder(Order $order): ?LogisticsCenter
    {
        $city = $order->city_id ? City::find($order->city_id) : $this->router->cityFor($order);

        return $this->forCity($city?->id);
    }

    /** El horario de ese día, con la excepción si la hay: ["08:00", "19:00"] o null si no abre. */
    public function hoursOn(LogisticsCenter $center, CarbonInterface $day): ?array
    {
        $exception = $center->exceptions()->whereDate('date', $day->toDateString())->first();
        if ($exception) {
            if ($exception->is_closed) {
                return null;
            }
            if ($exception->opens_at && $exception->closes_at) {
                return [substr($exception->opens_at, 0, 5), substr($exception->closes_at, 0, 5)];
            }
        }
        return self::hoursIn($center->opening_hours ?? self::DEFAULT_HOURS, $day);
    }

    /** El horario de ese día en un horario por día ISO (el del centro o las franjas de un rider). */
    public static function hoursIn(array $schedule, CarbonInterface $day): ?array
    {
        $hours = $schedule[(string) $day->dayOfWeekIso] ?? null;

        return is_array($hours) && count($hours) === 2 ? [$hours[0], $hours[1]] : null;
    }

    public static function inHours(?array $hours, CarbonInterface $at): bool
    {
        if (!$hours) {
            return false;
        }
        $time = $at->format('H:i');

        return $time >= $hours[0] && $time < $hours[1];
    }

    public function inWindow(LogisticsCenter $center, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return self::inHours($this->hoursOn($center, $at), $at);
    }

    public function state(LogisticsCenter $center, ?CarbonInterface $at = null): string
    {
        if (!$center->is_active) {
            return self::INACTIVE;
        }
        // Con un turno abierto se despacha aunque el operador lo haya abierto antes de hora o se pase de la hora de
        // cierre: es quien está ahí para entregar la mercancía (§4.2). Sin turno, dentro del horario: SIN OPERADOR.
        if ($center->activeShift()->exists()) {
            return self::OPEN;
        }

        return $this->inWindow($center, $at) ? self::NO_OPERATOR : self::CLOSED;
    }

    public function canDispatch(LogisticsCenter $center, ?CarbonInterface $at = null): bool
    {
        return $this->state($center, $at) === self::OPEN;
    }

    /** §4.2: las órdenes nuevas se sueltan solo hasta el cutoff. Sin cutoff no se puede encender el centro. */
    public function beforeCutoff(LogisticsCenter $center, ?CarbonInterface $at = null): bool
    {
        if (!$center->dispatch_cutoff) {
            return false;
        }

        return ($at ?? now())->format('H:i') < substr($center->dispatch_cutoff, 0, 5);
    }

    /** Se puede soltar una orden nueva a logística ahora mismo (§4.2 puntos 3 y 5, §5). */
    public function canRelease(LogisticsCenter $center, ?CarbonInterface $at = null): bool
    {
        return $this->canDispatch($center, $at) && $this->beforeCutoff($center, $at);
    }

    /** Lo que falta para poder encender el centro. */
    public function activationProblems(LogisticsCenter $center): array
    {
        $problems = [];
        if (!$center->dispatch_cutoff) {
            $problems[] = 'Falta la hora límite para soltar órdenes nuevas (§4.2: obligatoria antes de encenderlo).';
        }
        if (!$center->warehouse?->hasCoordinates()) {
            $problems[] = 'El almacén del centro no tiene coordenadas: sin ellas no se calculan rutas ni ETA (§7.1).';
        }
        if (!$center->operators()->exists()) {
            $problems[] = 'El centro no tiene operadores (§4).';
        }

        return $problems;
    }
}
