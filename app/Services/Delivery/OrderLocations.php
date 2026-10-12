<?php

namespace App\Services\Delivery;

use App\Jobs\ResolveOrderLocation;
use App\Models\AgencyTrip;
use App\Models\Order;
use App\Models\OrderLocationHistory;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * La ubicación del cliente (Módulo 3 de Fran, §7, §17 a §19):
 * - se guardan el link original, la latitud y la longitud; el link se resuelve una sola vez, al registrarlo (§7);
 * - si no se puede resolver, la orden queda con la ubicación "pendiente de resolver" o "inválida", y se avisa (§7);
 * - cada cambio queda en el historial con lo de antes, lo de después, quién, cuándo y el motivo; nunca se sobrescribe
 *   (§19). Si ya hubo un intento de entrega, se marca (§18).
 */
class OrderLocations
{
    public function __construct(private MapsLinkResolver $resolver)
    {
    }

    /** La ubicación de la orden cambió (o se registró por primera vez). */
    public function changed(Order $order, ?string $previousUrl, ?float $previousLat, ?float $previousLng, ?int $userId, ?string $reason = null): OrderLocationHistory
    {
        $history = OrderLocationHistory::create([
            'order_id' => $order->id,
            'previous_url' => $previousUrl,
            'previous_lat' => $previousLat,
            'previous_lng' => $previousLng,
            'new_url' => $order->location,
            'changed_by' => $userId,
            'reason' => $reason,
            'after_attempt' => AgencyTrip::where('order_id', $order->id)->whereNull('voided_at')->exists(),
            'changed_at' => now(),
        ]);

        // Lo que se lee sin salir a Internet, al momento; el enlace corto, en la cola (no hace esperar a la vendedora)
        $short = MapsLinkResolver::isShortLink($order->location);
        $this->apply($order, $history, $short
            ? ['status' => MapsLinkResolver::PENDING, 'lat' => null, 'lng' => null, 'note' => 'Leyendo el enlace…']
            : $this->resolver->resolve($order->location, false, $this->reference($order)));
        if ($short) {
            ResolveOrderLocation::dispatch($order->id, $history->id);
        }

        return $history;
    }

    /** Resuelve del todo (siguiendo el enlace corto) y guarda el resultado. Lo usa la cola. */
    public function resolveNow(Order $order, ?OrderLocationHistory $history = null): array
    {
        $result = $this->resolver->resolve($order->location, true, $this->reference($order));
        $this->apply($order, $history, $result);

        return $result;
    }

    /**
     * El punto de referencia para un código plus corto: el almacén de la agencia de la orden o, si no tiene coordenadas
     * (o la orden todavía no tiene agencia), el de una agencia de su ciudad. Tiene que estar a menos de medio grado.
     */
    private function reference(Order $order): ?array
    {
        $agencies = array_filter([$order->agency_id]);
        if ($order->city_id) {
            $agencies = array_merge($agencies, DB::table('city_agency')->where('city_id', $order->city_id)->where('is_active', true)->pluck('agency_id')->all());
        }
        foreach (array_unique($agencies) as $agencyId) {
            $w = Warehouse::where('user_id', $agencyId)->whereNotNull('latitude')->orderByDesc('is_active')->orderBy('id')->first(['latitude', 'longitude']);
            if ($w) {
                return [(float) $w->latitude, (float) $w->longitude];
            }
        }
        return null;
    }

    /** Guarda las coordenadas en la orden (sin observers: no es un cambio de la orden) y en el historial. */
    private function apply(Order $order, ?OrderLocationHistory $history, array $result): void
    {
        $order->forceFill([
            'location_lat' => $result['lat'],
            'location_lng' => $result['lng'],
            'location_status' => trim((string) $order->location) === '' ? null : $result['status'],
            'location_note' => $result['note'] ? mb_substr($result['note'], 0, 200) : null,
        ])->saveQuietly();
        $history?->update(['new_lat' => $result['lat'], 'new_lng' => $result['lng'], 'new_status' => $result['status']]);
    }
}
