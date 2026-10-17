<?php

namespace App\Services\Agencies;

use App\Constants\OrderStatus;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assignment\AssignOrderService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ciudades y agencias que se apagan, y el horario de cada agencia (Fran, 2026-10-17: "Valencia no puede trabajar y
 * entonces necesito que sus órdenes no pasen a asignado a vendedora"; "Maracay todos los días cierra a las 5 de la
 * tarde"; 10:54: "los horarios deberían ser por agencia, porque cada agencia puede tener un horario distinto").
 *  - Una agencia trabaja si está encendida y, si tiene horario, está dentro de él. Una que no trabaja no recibe órdenes
 *    en el reparto de su ciudad (AgencyRouter::cityAgencies).
 *  - Una ciudad no tiene horario: trabaja si está encendida y, si tiene agencias activas en el reparto, al menos una de
 *    ellas trabaja.
 *  - La orden de una ciudad que no trabaja no pasa a vendedora: se queda donde está, sin vendedora, y queda anotada
 *    (Setting SETTING_WAITING). Cuando la ciudad vuelve a trabajar (al encenderla o al llegar su hora) se reparte como
 *    siempre (release, cada minuto). Una en "Sin Stock" que recupera stock tampoco sale mientras tanto.
 *  - Las que ya tenían vendedora no se tocan.
 */
class WorkingHours
{
    public const SETTING_WAITING = 'agencies.orders_waiting_city';

    public function __construct(private AgencyRouter $router) {}

    public function agencyWorks(User $agency, ?Carbon $at = null): bool
    {
        return $this->agencyStop($agency, $at) === null;
    }

    public function cityWorks(City $city, ?Carbon $at = null): bool
    {
        return $this->cityStop($city, $at) === null;
    }

    /** Por qué la agencia no trabaja ahora ("está apagada", "trabaja de 08:00 a 17:00"), o null si trabaja. */
    public function agencyStop(User $agency, ?Carbon $at = null): ?string
    {
        if ($agency->agency_is_working === false) {
            return 'está apagada';
        }

        return self::inHours($at ?? now(), $agency->agency_works_from, $agency->agency_works_until)
            ? null : 'trabaja ' . self::hoursLabel($agency->agency_works_from, $agency->agency_works_until);
    }

    /**
     * Por qué la ciudad no trabaja ahora, en una frase ("Carabobo está apagada", "Dalgo, la agencia de Aragua, trabaja
     * hasta las 17:00"), o null si trabaja.
     */
    public function cityStop(City $city, ?Carbon $at = null): ?string
    {
        $at ??= now();
        if ($city->is_working === false) {
            return "{$city->name} está apagada";
        }
        $agencies = $city->agencies()->wherePivot('is_active', true)->get();
        if ($agencies->isNotEmpty() && !$agencies->contains(fn (User $a) => $this->agencyWorks($a, $at))) {
            return $agencies->count() === 1
                ? "{$agencies->first()->names}, la agencia de {$city->name}, " . $this->agencyStop($agencies->first(), $at)
                : "ninguna agencia de {$city->name} está trabajando";
        }

        return null;
    }

    /** Si la orden no puede pasar a vendedora porque su ciudad no trabaja, el motivo; si puede, null. */
    public function orderStop(Order $order): ?string
    {
        $city = $this->router->cityFor($order);

        return $city ? $this->cityStop($city) : null;
    }

    /**
     * Si la ciudad de la orden no trabaja, la anota para repartirla cuando vuelva (con una línea en su historial, una
     * sola vez) y devuelve true. Si trabaja, false.
     */
    public function holdIfStopped(Order $order): bool
    {
        $why = $this->orderStop($order);
        if ($why === null) {
            return false;
        }
        DB::transaction(function () use ($order, $why) {
            $ids = $this->waitingIds(true);
            if (in_array($order->id, $ids, true)) {
                return;
            }
            $ids[] = $order->id;
            Setting::set(self::SETTING_WAITING, $ids);
            OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => 'city_stopped',
                'description' => "No pasa a vendedora: {$why}. Se reparte cuando vuelva a trabajar.",
            ]);
        });

        return true;
    }

    /** @return array<int, int> */
    public function waitingIds(bool $lock = false): array
    {
        $query = Setting::where('key', self::SETTING_WAITING);
        $row = $lock ? $query->lockForUpdate()->first() : $query->first();
        $ids = $row ? json_decode($row->value, true) : [];

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** @return array<int, int>  city_id => órdenes que esperan que la ciudad trabaje. */
    public function waitingByCity(): array
    {
        $ids = $this->waitingIds();

        return $ids ? Order::whereIn('id', $ids)->whereNotNull('city_id')
            ->groupBy('city_id')->selectRaw('city_id, COUNT(*) as c')->pluck('c', 'city_id')->map(fn ($c) => (int) $c)->all() : [];
    }

    /**
     * Reparte las órdenes anotadas cuya ciudad ya trabaja y cuya tienda está abierta, como se hubieran repartido: la de
     * "Sin Stock" que ya tiene stock sale como cuando llega mercancía; las demás, como en el reparto de la mañana. Salen
     * de la lista las que ya tienen vendedora o terminaron. Devuelve cuántas se procesaron.
     */
    public function release(): int
    {
        $ids = $this->waitingIds();
        if ($ids === []) {
            return 0;
        }
        $assigner = app(AssignOrderService::class);
        $done = [];
        $closed = [OrderStatus::ENTREGADO, OrderStatus::CANCELADO, OrderStatus::RECHAZADO];
        foreach ($ids as $id) {
            $order = Order::with('status')->find($id);
            // La de "Sin Stock" puede tener vendedora y esperar igual (recuperó stock con la ciudad apagada)
            $sinStock = $order?->status?->description === OrderStatus::SIN_STOCK;
            if (!$order || (!$sinStock && $order->agent_id) || in_array($order->status?->description, $closed, true)) {
                $done[] = $id;
                continue;
            }
            if ($this->orderStop($order) !== null || !$order->shop_id || !$assigner->isBusinessOpen($order->shop_id)) {
                continue;
            }
            try {
                if ($sinStock) {
                    app(InventoryService::class)->recoverFromSinStock($order);
                    $order->refresh();
                    if (!$order->agent_id && $order->status?->description === OrderStatus::SIN_STOCK) {
                        $assigner->assignOne($order);
                    }
                } else {
                    $assigner->assignWaiting($order);
                }
            } catch (\Throwable $e) {
                Log::error("WorkingHours: la orden #{$id} no se pudo repartir al volver a trabajar su ciudad: " . $e->getMessage());
                continue;
            }
            $done[] = $id;
        }
        if ($done !== []) {
            DB::transaction(function () use ($done) {
                Setting::set(self::SETTING_WAITING, array_values(array_diff($this->waitingIds(true), $done)));
            });
        }

        return count($done);
    }

    /** Dentro del horario. Sin horario, todo el día; solo "desde" o solo "hasta", desde o hasta esa hora. */
    public static function inHours(Carbon $at, ?string $from, ?string $until): bool
    {
        if (!$from && !$until) {
            return true;
        }
        $time = $at->format('H:i:s');
        $from = $from ? self::time($from) : '00:00:00';
        $until = $until ? self::time($until) : '24:00:00';
        if ($until <= $from) {
            // Pasa la medianoche (20:00 a 06:00)
            return $time >= $from || $time < $until;
        }

        return $time >= $from && $time < $until;
    }

    /** "de 08:00 a 17:00", "hasta las 17:00", "desde las 08:00". */
    public static function hoursLabel(?string $from, ?string $until): string
    {
        $from = $from ? substr(self::time($from), 0, 5) : null;
        $until = $until ? substr(self::time($until), 0, 5) : null;

        return match (true) {
            $from && $until => "de {$from} a {$until}",
            (bool) $until => "hasta las {$until}",
            (bool) $from => "desde las {$from}",
            default => 'todo el día',
        };
    }

    private static function time(string $value): string
    {
        return strlen($value) === 5 ? "{$value}:00" : $value;
    }
}
