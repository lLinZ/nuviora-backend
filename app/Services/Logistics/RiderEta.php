<?php

namespace App\Services\Logistics;

use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\RiderProfile;
use App\Services\Delivery\GoogleRoutesException;
use Illuminate\Support\Collection;

/**
 * Los tiempos por carretera que usa la selección de riders (§7.1): rider → centro y centro → cliente.
 * - Rider → centro: una sola consulta para todos, y se reusa mientras tenga menos de un minuto (varias órdenes en el
 *   mismo minuto no vuelven a preguntar lo mismo). Decisión nuestra, para el costo de Google.
 * - Centro → cliente: una vez por orden; se guarda en la fila logística.
 * Si Google no responde, no se inventa un tiempo con la línea recta (§33): el rider queda sin ETA.
 */
class RiderEta
{
    public const MAX_AGE_SECONDS = 60;

    public function __construct(private RouteMatrixClient $matrix)
    {
    }

    /**
     * Pone al día el ETA al centro de esos riders.
     *
     * @param  Collection<int, RiderProfile>  $profiles
     * @return string|null  el error de Google, si lo hubo
     */
    public function refreshToCenter(Collection $profiles, LogisticsCenter $center, int $maxAge = self::MAX_AGE_SECONDS): ?string
    {
        $warehouse = $center->warehouse;
        if (!$warehouse?->hasCoordinates()) {
            return 'El almacén del centro no tiene coordenadas.';
        }
        $stale = $profiles->filter(fn (RiderProfile $p) => $p->hasLocation()
            && (!$p->eta_computed_at || $p->eta_computed_at->lt(now()->subSeconds($maxAge))))->keyBy('id');
        if ($stale->isEmpty()) {
            return null;
        }

        try {
            $result = $this->matrix->matrix(
                $stale->map(fn (RiderProfile $p) => [$p->last_lat, $p->last_lng])->all(),
                ['centro' => [$warehouse->latitude, $warehouse->longitude]]
            );
        } catch (GoogleRoutesException $e) {
            return $e->getMessage();
        }

        foreach ($stale as $id => $profile) {
            $cell = $result[$id]['centro'] ?? null;
            $profile->forceFill([
                'eta_center_seconds' => $cell['seconds'] ?? null,
                'eta_center_meters' => $cell['meters'] ?? null,
                'eta_computed_at' => now(),
            ])->save();
        }

        return null;
    }

    /** El tramo centro → cliente de la orden (§7.1 punto 3), calculado una vez. */
    public function centerToClient(LogisticsOrder $row): ?array
    {
        if ($row->center_client_at) {
            return $row->center_client_seconds !== null
                ? ['seconds' => $row->center_client_seconds, 'meters' => $row->center_client_meters] : null;
        }
        $order = $row->order;
        $warehouse = $row->center?->warehouse;
        if ($order?->location_lat === null || $order?->location_lng === null || !$warehouse?->hasCoordinates()) {
            return null;
        }
        try {
            $cell = $this->matrix->matrix([[$warehouse->latitude, $warehouse->longitude]], [[(float) $order->location_lat, (float) $order->location_lng]])[0][0] ?? null;
        } catch (GoogleRoutesException) {
            return null; // se vuelve a intentar en la próxima ronda
        }
        $row->forceFill([
            'center_client_seconds' => $cell['seconds'] ?? null,
            'center_client_meters' => $cell['meters'] ?? null,
            'center_client_at' => now(),
        ])->save();

        return $cell;
    }

    /** Kilómetros en línea recta: solo para descartar a los que no pueden llegar, nunca como ETA (§33). */
    public static function straightKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
