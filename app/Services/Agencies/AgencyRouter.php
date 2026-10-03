<?php

namespace App\Services\Agencies;

use App\Constants\OrderStatus;
use App\Models\AssignmentPool;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\Status;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\OrderAssignedNotification;
use App\Services\Assignment\Weighted\SmoothWeightedRoundRobin;
use App\Services\Inventory\StockCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reparto de órdenes entre las agencias de una ciudad (tareas 3c y 6, Fran §11-19).
 *  - Candidatas: las agencias activas de la ciudad (city_agency) que tienen stock útil para toda la
 *    orden en su almacén y no llegaron a su máximo de órdenes activas (users.max_active_orders).
 *  - Activas de una agencia: Asignar a agencia, Asignar repartidor, Asignado a repartidor y En ruta.
 *  - Entre las candidatas se elige con el mismo Smooth Weighted Round Robin que para las vendedoras,
 *    con el % de cada agencia en esa ciudad (todas sin % = parejo). El saldo se guarda por ciudad y
 *    con bloqueo; quien sale por cupo vuelve sin compensación.
 *  - Si todas las que tienen stock están llenas, la orden no espera: se reparte entre ellas en partes
 *    iguales, sin mirar el % (Fran, 2026-09-30 y 2026-10-02). En cuanto una libera cupo, vuelve a
 *    recibir solo la que tiene cupo, con su %.
 *    La reasignación en bloque sí respeta el máximo (salvo que el Admin lo fuerce).
 *  - "Pendiente de asignación a agencia" queda para las órdenes que ya esperaban: processWaiting()
 *    las reparte en la siguiente pasada.
 * Una agencia elegida a mano (orders.agency_locked) no se cambia.
 */
class AgencyRouter
{
    public const ACTIVE_STATUSES = [
        OrderStatus::ASIGNAR_A_AGENCIA,
        OrderStatus::ASIGNAR_REPARTIDOR,
        OrderStatus::ASIGNADO_A_REPARTIDOR,
        OrderStatus::EN_RUTA,
    ];

    private ?array $activeStatusIds = null;

    /** Ciudad de la orden: la guardada o la que coincide con la provincia o ciudad del cliente. */
    public function cityFor(Order $order): ?City
    {
        if ($order->city_id) {
            return City::find($order->city_id);
        }
        $client = $order->client;
        foreach ([$client?->province, $client?->city] as $name) {
            if ($name && ($city = City::whereRaw('UPPER(name) = ?', [mb_strtoupper(trim($name))])->first())) {
                return $city;
            }
        }

        return null;
    }

    /** @return Collection<int, User>  agencias activas de la ciudad, con ->pivot->weight. */
    public function cityAgencies(City $city): Collection
    {
        return $city->agencies()->wherePivot('is_active', true)->orderBy('users.id')->get();
    }

    /**
     * Elige agencia para la orden. No cambia la orden; sí avanza el saldo del reparto de la ciudad.
     *
     * @param  array<int>|null  $onlyIds  limitar a estas agencias (reasignación en bloque).
     * @param  bool  $overflow  si todas las que tienen stock están llenas, elegir igual entre ellas
     *         (detalle 'todas_llenas' => true). Sin esto, el motivo es todas_llenas.
     * @return array{0: ?User, 1: string, 2: array}  agencia, motivo (ok, sin_ciudad, sin_agencias,
     *         sin_stock, todas_llenas) y detalle.
     */
    public function pick(Order $order, ?array $onlyIds = null, bool $ignoreCapacity = false, bool $overflow = true): array
    {
        $city = $this->cityFor($order);
        if (!$city) {
            return [null, 'sin_ciudad', []];
        }
        $agencies = $this->cityAgencies($city);
        if ($onlyIds !== null) {
            $agencies = $agencies->whereIn('id', $onlyIds)->values();
        }
        if ($agencies->isEmpty()) {
            return [null, 'sin_agencias', ['city' => $city->name]];
        }

        $withStock = $agencies->filter(fn (User $a) => $this->hasStockAt($order, $a->id))->values();
        if ($withStock->isEmpty()) {
            return [null, 'sin_stock', ['city' => $city->name]];
        }

        $available = $ignoreCapacity ? $withStock : $withStock->filter(fn (User $a) => $this->hasRoom($a, $order))->values();
        $saturated = $available->isEmpty();
        if ($saturated && !$overflow) {
            return [null, 'todas_llenas', ['city' => $city->name]];
        }
        if ($saturated) {
            $available = $withStock;
        }

        $weights = $this->weights($available);
        if ($weights === []) {
            return [null, 'sin_agencias', ['city' => $city->name]];
        }
        if ($saturated) {
            // Todas llenas: partes iguales entre las que reciben con su % (una en 0 % sigue fuera)
            $weights = \App\Services\Assignment\WeightedAssigner::evenly($weights);
        }

        $picked = DB::transaction(function () use ($city, $weights, $saturated) {
            // Con todas llenas el turno se lleva aparte, para no descompensar el reparto por % de siempre
            $pool = "city:{$city->id}" . ($saturated ? ':llenas' : '');
            AssignmentPool::query()->insertOrIgnore(['key' => $pool, 'created_at' => now(), 'updated_at' => now()]);
            $row = AssignmentPool::where('key', $pool)->lockForUpdate()->firstOrFail();
            [$picked, $current] = SmoothWeightedRoundRobin::pick($weights, $row->state ?? []);
            $row->state = $current;
            $row->save();

            return $picked;
        });

        return [$available->firstWhere('id', $picked), 'ok', ['city' => $city->name, 'city_id' => $city->id, 'todas_llenas' => $saturated]];
    }

    /** Deja en el historial que la orden se asignó con todas las agencias de la ciudad en su máximo. */
    public function noteOverflow(Order $order, User $agency, string $cityName): void
    {
        $this->activity($order, "Todas las agencias de {$cityName} con el producto estaban en su máximo: la orden no espera y se asignó a {$agency->names} (con todas llenas se reparten en partes iguales).");
    }

    /** Pone la agencia en la orden (sin guardar): su almacén, su ciudad y el costo de envío de la ciudad. */
    public function place(Order $order, User $agency): void
    {
        $order->agency_id = $agency->id;
        $order->warehouse_id = Warehouse::where('user_id', $agency->id)->value('id');
        if ($city = $this->cityFor($order)) {
            $order->city_id = $city->id;
            $order->delivery_cost = $city->delivery_cost_usd;
        }
    }

    /**
     * Antes de dar una orden por "Sin Stock" (al crearla o al revisar el Kanban): si su agencia no
     * tiene stock pero otra de la misma ciudad sí, la orden pasa a esa. Solo si nadie la eligió a mano.
     */
    public function provisional(Order $order): bool
    {
        if ($order->agency_locked || $order->isStockDeducted()) {
            return false;
        }
        if ($order->agency_id && $this->hasStockAt($order, (int) $order->agency_id)) {
            return false;
        }
        $city = $this->cityFor($order);
        if (!$city) {
            return false;
        }
        $target = $this->cityAgencies($city)
            ->sortByDesc(fn (User $a) => (float) ($a->pivot->weight ?? 0))
            ->first(fn (User $a) => $a->id !== (int) $order->agency_id && $this->hasStockAt($order, $a->id));
        if (!$target) {
            return false;
        }

        $from = $order->agency_id ? User::find($order->agency_id)?->names : null;
        $this->place($order, $target);
        $order->save();
        $this->activity($order, 'Agencia cambiada a ' . $target->names . ($from ? " (antes {$from}, sin stock)" : '') . ': es la de la ciudad que tiene stock.');

        return true;
    }

    /** La agencia tiene lugar para una orden más (sin contar esa orden si ya es suya). */
    public function hasRoom(User $agency, ?Order $except = null): bool
    {
        if ($agency->max_active_orders === null) {
            return true;
        }
        $active = Order::where('agency_id', $agency->id)
            ->whereIn('status_id', $this->activeStatusIds())
            ->when($except?->id, fn ($q) => $q->where('id', '!=', $except->id))
            ->count();

        return $active < (int) $agency->max_active_orders;
    }

    /** @return array<int, int>  agency_id => órdenes activas. */
    public function activeCounts(array $agencyIds): array
    {
        if ($agencyIds === []) {
            return [];
        }

        return Order::whereIn('agency_id', $agencyIds)
            ->whereIn('status_id', $this->activeStatusIds())
            ->groupBy('agency_id')
            ->selectRaw('agency_id, COUNT(*) as c')
            ->pluck('c', 'agency_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** El almacén de la agencia tiene stock útil para toda la orden. */
    public function hasStockAt(Order $order, int $agencyId): bool
    {
        $warehouseId = Warehouse::where('user_id', $agencyId)->where('is_active', true)->value('id');
        if (!$warehouseId) {
            return false;
        }
        // Por producto y por variante: una talla que la agencia no tiene no pasa (tarea 4)
        return app(StockCheck::class)->at($order, $warehouseId)['ok'];
    }

    /**
     * Reparte las órdenes que esperan agencia (la más vieja primero). Una ciudad que sigue llena no
     * se vuelve a revisar en la misma pasada. Devuelve cuántas se asignaron.
     */
    public function processWaiting(int $limit = 200): int
    {
        $pendingId = Status::where('description', OrderStatus::PENDIENTE_AGENCIA)->value('id');
        $targetId = Status::where('description', OrderStatus::ASIGNAR_A_AGENCIA)->value('id');
        if (!$pendingId || !$targetId) {
            return 0;
        }

        $assigned = 0;
        $full = [];
        foreach (Order::where('status_id', $pendingId)->orderBy('updated_at')->orderBy('id')->limit($limit)->get() as $order) {
            $cityId = $order->city_id;
            if ($cityId && isset($full[$cityId])) {
                continue;
            }
            [$agency, $reason, $info] = $this->pick($order);
            if (!$agency) {
                if ($reason === 'todas_llenas' && isset($info['city'])) {
                    $full[$cityId ?? 0] = true;
                }
                continue;
            }

            $this->place($order, $agency);
            $order->status_id = $targetId;
            $order->received_at ??= now();
            $order->save();
            $this->activity($order, !empty($info['todas_llenas'])
                ? "La orden esperaba agencia: ya no se espera y pasó a {$agency->names} (todas las de {$info['city']} siguen en su máximo; se reparten en partes iguales)."
                : "Una agencia de {$info['city']} liberó cupo: la orden pasó a {$agency->names}.");
            try {
                $agency->notify(new OrderAssignedNotification($order, "Nueva orden asignada a tu agencia: {$order->number_label}"));
            } catch (\Throwable $e) {
                report($e);
            }
            event(new \App\Events\OrderUpdated($order->fresh(['status', 'client', 'agent', 'agency', 'deliverer'])));
            $assigned++;
        }

        return $assigned;
    }

    /** % de cada agencia entre las disponibles. Todas sin % = parejo; con %, una en 0 no recibe. */
    public function weights(Collection $agencies): array
    {
        $set = $agencies->filter(fn (User $a) => $a->pivot?->weight !== null);
        $weights = [];
        foreach ($agencies as $a) {
            $weights[$a->id] = $set->isEmpty() ? 1.0 : (float) ($a->pivot->weight ?? 0);
        }

        return array_filter($weights, fn ($w) => $w > 0);
    }

    public function activeStatusIds(): array
    {
        return $this->activeStatusIds ??= Status::whereIn('description', self::ACTIVE_STATUSES)->pluck('id')->all();
    }

    private function activity(Order $order, string $description): void
    {
        try {
            OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => Auth::id(),
                'action' => 'agency_routing',
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
