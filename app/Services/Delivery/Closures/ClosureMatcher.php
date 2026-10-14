<?php

namespace App\Services\Delivery\Closures;

use App\Models\AgencyClosureRow;
use App\Models\AgencyTrip;
use App\Models\AgencyWeeklyClosure;
use App\Models\OrderDeliveryRoute;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Empareja cada fila del archivo con una carrera de la agencia en esa semana (Módulo 3 de Fran, §27 y §28):
 *   1. número de orden, si coincide con una sola orden → match directo;
 *   2. teléfono normalizado;
 *   3. nombre + fecha, solo como respaldo;
 *   4. la IA propone las que quedan (respuesta de Fran a la pregunta 1), y el código decide si hay certeza;
 *   5. lo demás, a revisión manual. Nunca un match ambiguo en silencio: si hay varias posibles, queda "posible".
 * Se empareja con carreras y no con órdenes porque cada intento se paga (tarea 5): una orden con dos carreras en la semana
 * puede tener dos filas, y una segunda fila para una sola carrera es un cobro repetido.
 */
class ClosureMatcher
{
    /** @var Collection<int, AgencyTrip> */
    private Collection $trips;
    /** @var array<int, int> carrera → fila que la tomó */
    private array $taken = [];

    public function __construct(private ClosureAi $ai)
    {
    }

    /** Las carreras de la agencia en la semana, con su orden y su cliente. */
    public static function weekTrips(AgencyWeeklyClosure $closure): Collection
    {
        return AgencyTrip::with(['order:id,name,order_number,client_id,location,city_id', 'order.client:id,first_name,last_name,phone,address1,address2,city'])
            ->where('agency_id', $closure->agency_id)
            ->whereBetween('trip_date', [$closure->period_start->toDateString(), $closure->period_end->toDateString()])
            ->whereNull('voided_at')
            ->orderBy('started_at')->orderBy('id')
            ->get();
    }

    /**
     * Empareja las filas (ya guardadas y normalizadas). Devuelve cuántas fueron por cada método.
     * @param Collection<int, AgencyClosureRow> $rows
     */
    public function match(AgencyWeeklyClosure $closure, Collection $rows): array
    {
        $this->trips = self::weekTrips($closure)->keyBy('id');
        $this->taken = [];
        $byOrderKey = [];
        $byPhone = [];
        foreach ($this->trips as $trip) {
            foreach (ClosureNormalizer::orderKeysFor($trip->order?->name, $trip->order?->order_number) as $k) {
                $byOrderKey[$k][] = $trip->id;
            }
            if ($p = ClosureNormalizer::phone($trip->order?->client?->phone)) {
                $byPhone[$p][] = $trip->id;
            }
        }

        // 1. Número de orden (§27.1)
        $byOrder = [];
        foreach ($rows as $row) {
            if ($row->order_key === null) {
                continue;
            }
            $byOrder[$row->id] = true;
            $ids = $byOrderKey[$row->order_key] ?? [];
            if (!$ids && preg_match('/^\d+$/', $row->order_key)) {
                $ids = $byOrderKey['MAN-' . $row->order_key] ?? []; // "27344" escrito sin el MAN-
            }
            $orders = collect($ids)->map(fn ($id) => $this->trips[$id]->order_id)->unique();
            if ($orders->count() === 1) {
                $this->linkToOrder($row, $orders->first(), 'order_id');
            } elseif ($orders->count() > 1) {
                $this->possible($row, collect($ids), 'Ese número de orden coincide con ' . $orders->count() . ' órdenes de la semana (tiendas distintas).');
            } elseif (!$this->outsideWeek($row, $closure)) {
                unset($byOrder[$row->id]); // ese número no es de ninguna carrera: se busca por lo demás
            }
        }

        // 2. Teléfono (§27.2)
        foreach ($rows as $row) {
            if ($row->match_status !== AgencyClosureRow::UNMATCHED || $row->phone_key === null) {
                continue;
            }
            $orders = collect($byPhone[$row->phone_key] ?? [])->map(fn ($id) => $this->trips[$id]->order_id)->unique();
            if ($orders->count() === 1) {
                $this->linkToOrder($row, $orders->first(), 'phone');
            } elseif ($orders->count() > 1) {
                $this->possible($row, collect($byPhone[$row->phone_key]), 'Ese teléfono tiene ' . $orders->count() . ' órdenes en la semana.');
            }
        }

        // 3. Nombre + fecha (§27.3): el nombre completo del archivo (dos palabras o más) está en el de un solo cliente con
        // carrera libre ese día; si ese día no hay ninguno, el día antes o el después (las agencias anotan a veces la fecha
        // de entrega). Varias carreras de esa misma orden son sus intentos, no una duda: se toma la primera. Dos órdenes sí
        // lo son (caso 10), y también un cliente guardado con un solo nombre que podría ser el mismo; uno con otro apellido
        // es otra persona. Un solo nombre de pila ("jose") no alcanza: queda como posible.
        foreach ($rows as $row) {
            if ($row->match_status !== AgencyClosureRow::UNMATCHED || $row->client_name_key === null || $row->row_date === null) {
                continue;
            }
            $scored = $this->byName($row, 0);
            $when = 'ese día';
            if ($scored->where('score', '>=', 1.0)->isEmpty() && ($nearby = $this->byName($row, 1))->where('score', '>=', 1.0)->isNotEmpty()) {
                $scored = $nearby;
                $when = 'entre el día antes y el después';
            }
            if ($scored->isEmpty()) {
                continue;
            }
            $full = $scored->filter(fn ($s) => $s['score'] >= 1.0);
            $orders = $full->pluck('trip.order_id')->unique();
            $rivals = $scored->filter(fn ($s) => $s['score'] < 1.0 && count(ClosureNormalizer::nameTokens(self::clientKey($s['trip']))) < 2);
            $tokens = count(ClosureNormalizer::nameTokens($row->client_name_key));
            if ($tokens >= 2 && $orders->count() === 1 && $rivals->isEmpty()) {
                $this->link($row, $full->first()['trip'], 'name_date', AgencyClosureRow::MATCHED);
            } else {
                $this->possible($row, $scored->pluck('trip.id'), match (true) {
                    $orders->count() > 1 => 'Hay ' . $orders->count() . " órdenes de clientes con ese nombre {$when}.",
                    $full->isEmpty() => 'Solo coincide en parte el nombre (' . $row->client_name . ').',
                    $tokens < 2 => 'El archivo trae solo un nombre (' . $row->client_name . ').',
                    default => 'También podría ser un cliente guardado solo con el nombre de pila.',
                });
            }
        }

        // 4. La IA, para las que quedan (respuesta de Fran a la pregunta 1)
        $aiNote = null;
        $pending = $rows->filter(fn ($r) => in_array($r->match_status, [AgencyClosureRow::UNMATCHED, AgencyClosureRow::POSSIBLE], true)
            && !isset($byOrder[$r->id]) && ($r->client_name_key !== null || $r->phone_key !== null));
        if ($pending->isNotEmpty() && $this->free()->isNotEmpty()) {
            if ($this->ai->available()) {
                try {
                    $this->withAi($pending);
                } catch (Throwable $e) {
                    report($e);
                    $aiNote = 'La IA no respondió (' . mb_strimwidth($e->getMessage(), 0, 160) . '). Esas filas quedan para revisar.';
                }
            } else {
                $aiNote = 'Sin IA configurada: las filas sin número de orden ni teléfono quedan para revisar.';
            }
        }

        foreach ($rows as $row) {
            $row->save();
        }

        return [
            'by_method' => $rows->whereIn('match_status', [AgencyClosureRow::MATCHED])->countBy('match_method')->all(),
            'possible' => $rows->where('match_status', AgencyClosureRow::POSSIBLE)->count(),
            'unmatched' => $rows->where('match_status', AgencyClosureRow::UNMATCHED)->count(),
            'ai_note' => $aiNote,
        ];
    }

    /** La primera carrera libre de esa orden en la semana; si ya están todas tomadas, la última (cobro repetido). */
    private function linkToOrder(AgencyClosureRow $row, int $orderId, string $method): void
    {
        $trips = $this->trips->where('order_id', $orderId);
        $free = $trips->first(fn (AgencyTrip $t) => !isset($this->taken[$t->id]));
        if ($free) {
            $this->link($row, $free, $method, AgencyClosureRow::MATCHED);
            return;
        }
        // Todas sus carreras de la semana ya tienen fila: esta es otra fila para la misma carrera (§32: la revisa el Admin)
        $this->link($row, $trips->last(), $method, AgencyClosureRow::MATCHED);
        $row->match_note = 'Esta orden tiene ' . $trips->count() . ' carrera(s) en la semana y ya están en otras filas del archivo.';
    }

    public function link(AgencyClosureRow $row, AgencyTrip $trip, string $method, string $status): void
    {
        $route = self::routeOf($trip);
        $row->fill([
            'matched_trip_id' => $trip->id,
            'matched_order_id' => $trip->order_id,
            'delivery_route_id' => $route?->id,
            // §41: el costo que la carrera ya tiene guardado; no se recalcula con el tarifario de hoy
            'expected_cost' => $route && $route->calculation_status === OrderDeliveryRoute::CALCULATED ? $route->expected_cost : null,
            'match_method' => $method,
            'match_status' => $status,
            'match_note' => null,
            'candidate_trip_ids' => null,
        ]);
        $this->taken[$trip->id] ??= $row->id;
    }

    private function possible(AgencyClosureRow $row, Collection $tripIds, string $note): void
    {
        $row->fill([
            'match_status' => AgencyClosureRow::POSSIBLE,
            'match_method' => null,
            'match_note' => $note,
            'candidate_trip_ids' => $tripIds->unique()->take(10)->values()->all(),
        ]);
    }

    /**
     * El número de orden no está en las carreras de la semana. Si es de una carrera de esta agencia en otra semana, queda
     * como posible con esa carrera (true); si no es de ninguna, se anota y se sigue buscando por lo demás (false).
     */
    private function outsideWeek(AgencyClosureRow $row, AgencyWeeklyClosure $closure): bool
    {
        $key = $row->order_key;
        $names = ctype_digit($key) ? [$key, "#{$key}", "MAN-{$key}"] : [$key, "#{$key}"];
        $trip = AgencyTrip::with('order:id,name')->where('agency_id', $closure->agency_id)->whereNull('voided_at')
            ->whereHas('order', fn ($q) => $q->where(fn ($w) => $w->whereIn('name', $names)->orWhere('order_number', ctype_digit($key) ? $key : '-')))
            ->orderByDesc('trip_date')->first();
        if ($trip) {
            $this->possible($row, collect([$trip->id]), "La orden {$trip->order->name} tiene carrera de esta agencia el {$trip->trip_date->format('d/m')}, fuera de esta semana.");
            return true;
        }
        $row->match_note = "No hay ninguna carrera de esta agencia con la orden {$row->external_order_id}.";

        return false;
    }

    /** §27.4: la IA propone; la propuesta solo se toma si es de una carrera libre, con certeza y con algo que la respalde. */
    private function withAi(Collection $pending): void
    {
        $free = $this->free();
        $rowsText = $pending->map(fn (AgencyClosureRow $r) => implode(' | ', array_filter([
            "fila {$r->id}",
            $r->row_date ? 'fecha ' . $r->row_date->toDateString() : null,
            $r->client_name ? "cliente: {$r->client_name}" : null,
            $r->phone ? "teléfono: {$r->phone}" : null,
            $r->zone ? "zona: {$r->zone}" : null,
            $r->external_order_id ? "orden: {$r->external_order_id}" : null,
        ])))->implode("\n");
        $tripsText = $free->map(fn (AgencyTrip $t) => implode(' | ', array_filter([
            "id {$t->id}",
            'fecha ' . $t->trip_date->toDateString(),
            'pedido ' . ($t->order?->name ?? '?'),
            'cliente: ' . trim(($t->order?->client?->first_name ?? '') . ' ' . ($t->order?->client?->last_name ?? '')),
            $t->order?->client?->phone ? 'teléfono: ' . $t->order->client->phone : null,
            'dirección: ' . mb_substr(trim(($t->order?->client?->address1 ?? '') . ' ' . ($t->order?->client?->address2 ?? '') . ' ' . ($t->order?->client?->city ?? '')), 0, 120),
        ])))->implode("\n");

        $proposals = collect($this->ai->suggestMatches($rowsText, $tripsText))->keyBy('fila');
        $counts = $proposals->pluck('carrera_id')->filter()->countBy();
        foreach ($pending as $row) {
            $p = $proposals->get($row->id);
            $tripId = $p['carrera_id'] ?? null;
            if (!$tripId || !$free->has($tripId) || isset($this->taken[$tripId])) {
                continue;
            }
            $trip = $free[$tripId];
            $reason = 'IA: ' . mb_substr((string) $p['motivo'], 0, 300);
            $backed = ClosureNormalizer::nameScore($row->client_name_key, self::clientKey($trip)) >= 0.5
                || ($row->phone_key && $row->phone_key === ClosureNormalizer::phone($trip->order?->client?->phone));
            $near = $row->row_date === null || ClosureNormalizer::daysApart($row->row_date->toDateString(), $trip->trip_date->toDateString()) <= 1;
            // Caso 10 del §47: si ya había varias carreras posibles, la IA no decide sola, salvo que coincida el teléfono
            $phoneBacked = $row->phone_key && $row->phone_key === ClosureNormalizer::phone($trip->order?->client?->phone);
            $several = count($row->candidate_trip_ids ?? []) > 1 && !$phoneBacked;
            if ($p['confianza'] === 'alta' && $backed && $near && !$several && $counts[$tripId] === 1) {
                $this->link($row, $trip, 'ai', AgencyClosureRow::MATCHED);
                $row->match_note = $reason;
            } else {
                $this->possible($row, collect([$tripId])->merge($row->candidate_trip_ids ?? []), $reason . ' (confianza ' . $p['confianza'] . ')');
            }
        }
    }

    /** Las carreras libres de hasta $days días de la fila cuyo cliente se parece al nombre del archivo, con su puntaje. */
    private function byName(AgencyClosureRow $row, int $days): Collection
    {
        $date = $row->row_date->toDateString();

        return $this->free()
            ->filter(fn (AgencyTrip $t) => ClosureNormalizer::daysApart($date, $t->trip_date->toDateString()) <= $days)
            ->map(fn (AgencyTrip $t) => ['trip' => $t, 'score' => ClosureNormalizer::nameScore($row->client_name_key, self::clientKey($t))])
            ->filter(fn ($s) => $s['score'] >= 0.5)
            ->values();
    }

    /** @return Collection<int, AgencyTrip> */
    private function free(): Collection
    {
        return $this->trips->reject(fn (AgencyTrip $t) => isset($this->taken[$t->id]));
    }

    public static function clientKey(AgencyTrip $trip): ?string
    {
        return ClosureNormalizer::name(trim(($trip->order?->client?->first_name ?? '') . ' ' . ($trip->order?->client?->last_name ?? '')));
    }

    /** La ruta de la carrera (§18); si la carrera es de antes del módulo, la de su orden con esa agencia. */
    public static function routeOf(AgencyTrip $trip): ?OrderDeliveryRoute
    {
        if ($trip->delivery_route_id) {
            return OrderDeliveryRoute::find($trip->delivery_route_id);
        }

        return OrderDeliveryRoute::where('order_id', $trip->order_id)->where('agency_id', $trip->agency_id)
            ->orderByDesc('is_active')->orderByDesc('id')->first();
    }
}
