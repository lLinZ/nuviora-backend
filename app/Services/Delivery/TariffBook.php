<?php

namespace App\Services\Delivery;

use App\Models\AgencyClosureRow;
use App\Models\AgencyClosureSetting;
use App\Models\AgencyTariff;
use App\Models\DeliveryAuditLog;
use App\Models\OrderDeliveryRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los tarifarios de las agencias (Módulo 3 de Fran, §10 a §13, §41):
 * - uno por agencia y ciudad, con versiones y vigencia (§10, §13);
 * - rangos continuos desde < km ≤ hasta, sin solapes, huecos, duplicados ni negativos, y desde < hasta (§11);
 * - un cambio es una versión nueva; la anterior queda cerrada el día antes y las carreras ya calculadas no cambian
 *   (§13, §16, §41).
 */
class TariffBook
{
    /**
     * Lo que está mal en una lista de rangos, o null si está bien (§11). Cada rango: min_km, max_km (null solo en el
     * último: "en adelante"), price y, si corresponde, price_per_km por cada km sobre min_km.
     */
    public static function rangeErrors(array $ranges): ?string
    {
        if ($ranges === []) {
            return 'El tarifario necesita al menos un rango.';
        }
        $expectedMin = 0.0;
        foreach (array_values($ranges) as $i => $r) {
            $n = $i + 1;
            $min = isset($r['min_km']) ? (float) $r['min_km'] : null;
            $max = isset($r['max_km']) && $r['max_km'] !== '' ? (float) $r['max_km'] : null;
            $price = isset($r['price']) && $r['price'] !== '' ? (float) $r['price'] : null;
            $perKm = isset($r['price_per_km']) && $r['price_per_km'] !== '' ? (float) $r['price_per_km'] : null;

            if ($min === null || $min < 0 || ($max !== null && $max < 0) || ($price !== null && $price < 0) || ($perKm !== null && $perKm < 0)) {
                return "Rango {$n}: no se permiten valores vacíos ni negativos.";
            }
            if (abs($min - $expectedMin) > 0.0005) {
                return $i === 0
                    ? 'El primer rango tiene que empezar en 0 km.'
                    : "Rango {$n}: tiene que empezar donde termina el anterior ({$expectedMin} km); si no, hay un hueco o un solape.";
            }
            if ($max === null && $i !== count($ranges) - 1) {
                return "Rango {$n}: solo el último puede quedar sin \"hasta\" (en adelante).";
            }
            if ($max !== null && $min >= $max) {
                return "Rango {$n}: \"desde\" tiene que ser menor que \"hasta\".";
            }
            if ($price === null) {
                return "Rango {$n}: falta el precio.";
            }
            $expectedMin = $max ?? $min;
        }
        return null;
    }

    /** La versión vigente en una fecha (§13). */
    public function find(int $agencyId, int $cityId, string $date): ?AgencyTariff
    {
        return AgencyTariff::where('agency_id', $agencyId)->where('city_id', $cityId)->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('valid_from')->orderByDesc('version')
            ->with('ranges')->first();
    }

    /** La versión que corrigió la última llamada a createVersion: ['version' => n, 'routes' => carreras recalculadas]. */
    public ?array $corrected = null;

    /**
     * Crea una versión nueva vigente desde $validFrom y cierra la anterior el día antes (§13). Las rutas que esperaban
     * tarifario en esas fechas se cotizan con la distancia que ya tienen, sin volver a llamar a Google (§43).
     *
     * Si empieza el mismo día que la última, es una corrección (Fran, 2026-10-12: "me equivoqué colocando el tarifario
     * de Dalgo y ahora no me deja actualizarlo"): la nueva la reemplaza, la equivocada queda anulada y sus carreras se
     * recalculan. No es un cambio de precio (§13: ese sí es una versión nueva, desde otro día), así que solo se puede
     * mientras ningún cierre de la agencia haya usado esas carreras.
     */
    public function createVersion(int $agencyId, int $cityId, string $validFrom, array $ranges, ?string $notes, ?int $userId): AgencyTariff
    {
        $this->corrected = null;
        [$tariff, $replaced] = DB::transaction(function () use ($agencyId, $cityId, $validFrom, $ranges, $notes, $userId) {
            $last = AgencyTariff::where('agency_id', $agencyId)->where('city_id', $cityId)->where('is_active', true)->lockForUpdate()
                ->orderByDesc('valid_from')->orderByDesc('version')->first();
            $replaced = null;
            if ($last && $validFrom === $last->valid_from->toDateString()) {
                $closed = AgencyClosureRow::where('is_current', true)
                    ->whereIn('delivery_route_id', OrderDeliveryRoute::where('agency_tariff_id', $last->id)->select('id'))->exists();
                if ($closed) {
                    throw new \InvalidArgumentException("La versión {$last->version} ya se usó en un cierre de la agencia y no se puede corregir. Carga una versión nueva que empiece otro día.");
                }
                $replaced = $last;
                $last = null;
            } elseif ($last && $validFrom < $last->valid_from->toDateString()) {
                throw new \InvalidArgumentException('La versión nueva tiene que empezar después del ' . $last->valid_from->format('d/m/Y') . ', cuando empezó la anterior. Para corregir esa versión, guarda la nueva con la misma fecha.');
            }
            $version = (int) AgencyTariff::where('agency_id', $agencyId)->where('city_id', $cityId)->max('version') + 1;
            if ($replaced) {
                $replaced->update(['is_active' => false]);
                DeliveryAuditLog::record('tariff_corrected', 'agency_tariff', $replaced->id, self::snapshot($replaced->load('ranges')), ['is_active' => false, 'replaced_by_version' => $version], $userId);
            }
            if ($last && ($last->valid_to === null || $last->valid_to->toDateString() >= $validFrom)) {
                $before = ['valid_to' => $last->valid_to?->toDateString()];
                $last->valid_to = Carbon::parse($validFrom)->subDay()->toDateString();
                $last->save();
                DeliveryAuditLog::record('tariff_closed', 'agency_tariff', $last->id, $before, ['valid_to' => $last->valid_to->toDateString()], $userId);
            }
            $tariff = AgencyTariff::create([
                'agency_id' => $agencyId, 'city_id' => $cityId, 'version' => $version, 'valid_from' => $validFrom,
                'valid_to' => $replaced?->valid_to?->toDateString(), 'notes' => $notes, 'created_by' => $userId,
            ]);
            foreach (array_values($ranges) as $i => $r) {
                $tariff->ranges()->create([
                    'position' => $i + 1,
                    'min_km' => (float) $r['min_km'],
                    'max_km' => isset($r['max_km']) && $r['max_km'] !== '' ? (float) $r['max_km'] : null,
                    'price' => isset($r['price']) && $r['price'] !== '' ? (float) $r['price'] : null,
                    'price_per_km' => isset($r['price_per_km']) && $r['price_per_km'] !== '' ? (float) $r['price_per_km'] : null,
                ]);
            }
            DeliveryAuditLog::record('tariff_created', 'agency_tariff', $tariff->id, null, self::snapshot($tariff->load('ranges')), $userId);

            return [$tariff, $replaced];
        });

        $tariff->load('ranges');
        if ($replaced) {
            $routes = OrderDeliveryRoute::where('agency_tariff_id', $replaced->id)->get();
            foreach ($routes as $route) {
                $this->price($route, $tariff);
            }
            $this->corrected = ['version' => $replaced->version, 'routes' => $routes->count()];
        }
        $this->quotePending($tariff);

        return $tariff;
    }

    /** Lo que se guarda en la auditoría de una versión. */
    public static function snapshot(AgencyTariff $t): array
    {
        return [
            'agency_id' => $t->agency_id, 'city_id' => $t->city_id, 'version' => $t->version,
            'valid_from' => $t->valid_from?->toDateString(), 'valid_to' => $t->valid_to?->toDateString(),
            'ranges' => $t->ranges->map(fn ($r) => ['min_km' => $r->min_km, 'max_km' => $r->max_km, 'price' => $r->price, 'price_per_km' => $r->price_per_km])->all(),
        ];
    }

    /** El rango y el precio de una distancia con una versión (§9: sin redondear la distancia). */
    public function quote(AgencyTariff $tariff, int $meters): ?array
    {
        $range = $tariff->rangeFor($meters);

        return $range ? ['range' => $range, 'price' => $range->priceFor($meters)] : null;
    }

    /** Pone precio a las rutas con distancia que esperaban un tarifario vigente en su fecha (no cambia las calculadas). */
    public function quotePending(AgencyTariff $tariff): int
    {
        $routes = OrderDeliveryRoute::where('calculation_status', OrderDeliveryRoute::NO_TARIFF)
            ->where('agency_id', $tariff->agency_id)->where('city_id', $tariff->city_id)->whereNotNull('distance_meters')
            ->whereDate('tariff_date', '>=', $tariff->valid_from)
            ->when($tariff->valid_to, fn ($q) => $q->whereDate('tariff_date', '<=', $tariff->valid_to))
            ->get();
        foreach ($routes as $route) {
            $this->price($route, $tariff);
        }
        return $routes->count();
    }

    /**
     * Aplica una versión a una ruta que ya tiene distancia. Si pasa el límite de km de su agencia (Fran, 2026-10-12: 25 km
     * por defecto), queda "Distancia por revisar": guarda su costo, pero el cierre no lo cuenta hasta que el Admin apruebe
     * la distancia (casi siempre es una ubicación equivocada: carreras de $80).
     */
    public function price(OrderDeliveryRoute $route, ?AgencyTariff $tariff): void
    {
        $quote = $tariff ? $this->quote($tariff, (int) $route->distance_meters) : null;
        $status = !$tariff ? OrderDeliveryRoute::NO_TARIFF : ($quote ? OrderDeliveryRoute::CALCULATED : OrderDeliveryRoute::OUT_OF_RANGE);
        if ($status === OrderDeliveryRoute::CALCULATED && $route->distance_approved_at === null
            && (float) $route->distance_km > $this->reviewLimitKm((int) $route->agency_id)) {
            $status = OrderDeliveryRoute::DISTANCE_REVIEW;
        }
        $route->fill([
            'agency_tariff_id' => $tariff?->id,
            'agency_tariff_range_id' => $quote ? $quote['range']->id : null,
            'expected_cost' => $quote ? $quote['price'] : null,
            'calculation_status' => $status,
            'calculated_at' => now(),
            'error' => null,
        ])->save();
    }

    /** @var array<int, float> */
    private array $limits = [];

    /** Los km a partir de los cuales una carrera de la agencia se revisa (su configuración del cierre). */
    public function reviewLimitKm(int $agencyId): float
    {
        return $this->limits[$agencyId] ??= (float) AgencyClosureSetting::for($agencyId)->review_distance_km;
    }
}
