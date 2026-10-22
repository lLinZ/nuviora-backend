<?php

namespace App\Services\Logistics;

use App\Services\Delivery\GoogleRoutesClient;
use App\Services\Delivery\GoogleRoutesException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Tiempos por carretera para la logística propia (§7.1 punto 1: "usando ruta de conducción, no distancia en línea recta";
 * §33: "los ETA deben provenir de motor de rutas"). Usa la matriz de Google Routes con la misma clave del Módulo 3:
 * - POST https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix;
 * - DRIVE con TRAFFIC_AWARE: aquí importa cuánto tarda ahora, no la distancia estable de las carreras (§4 del Módulo 3);
 * - hasta 625 pares por petición; la respuesta es una lista con originIndex y destinationIndex, en cualquier orden.
 * La clave no se escribe en ningún log ni respuesta.
 */
class RouteMatrixClient
{
    public const URL = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';
    public const FIELD_MASK = 'originIndex,destinationIndex,duration,distanceMeters,status,condition';
    public const MAX_ELEMENTS = 625;

    public function configured(): bool
    {
        return (string) config('services.google_routes.key') !== '';
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $origins  [lat, lng]
     * @param  array<int, array{0: float, 1: float}>  $destinations
     * @return array<int, array<int, array{seconds: int, meters: int}|null>>  [origen][destino]; null = sin ruta
     */
    public function matrix(array $origins, array $destinations): array
    {
        $key = (string) config('services.google_routes.key');
        if ($key === '') {
            throw new GoogleRoutesException(GoogleRoutesException::CONFIG, 'Falta la clave de Google Routes en el servidor (GOOGLE_ROUTES_API_KEY).');
        }
        if ($origins === [] || $destinations === []) {
            return [];
        }
        if (count($origins) * count($destinations) > self::MAX_ELEMENTS) {
            throw new GoogleRoutesException(GoogleRoutesException::REQUEST, 'Demasiados pares para una sola consulta a Google.');
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => self::FIELD_MASK])
                ->post(self::URL, self::body($origins, $destinations));
        } catch (ConnectionException) {
            throw new GoogleRoutesException(GoogleRoutesException::NETWORK, 'Google Routes no respondió.');
        }
        if ($response->failed()) {
            throw GoogleRoutesClient::failure($response, $key);
        }

        // Google cuenta por posición; el resultado va con las claves que se pasaron (por ejemplo, el id del rider)
        $originKeys = array_keys($origins);
        $destinationKeys = array_keys($destinations);
        $result = [];
        foreach ($originKeys as $o) {
            foreach ($destinationKeys as $d) {
                $result[$o][$d] = null;
            }
        }
        foreach ((array) $response->json() as $element) {
            // En JSON de Google un 0 no se manda: sin el índice, es el 0
            $o = $originKeys[(int) ($element['originIndex'] ?? 0)] ?? null;
            $d = $destinationKeys[(int) ($element['destinationIndex'] ?? 0)] ?? null;
            $failed = !empty($element['status']['code'] ?? null) || (($element['condition'] ?? 'ROUTE_EXISTS') !== 'ROUTE_EXISTS');
            if (!$failed && $o !== null && $d !== null) {
                $result[$o][$d] = [
                    'seconds' => (int) rtrim((string) ($element['duration'] ?? '0s'), 's'),
                    'meters' => (int) ($element['distanceMeters'] ?? 0),
                ];
            }
        }

        return $result;
    }

    public static function body(array $origins, array $destinations): array
    {
        $point = fn (array $p) => ['waypoint' => ['location' => ['latLng' => ['latitude' => (float) $p[0], 'longitude' => (float) $p[1]]]]];

        return [
            'origins' => array_values(array_map($point, $origins)),
            'destinations' => array_values(array_map($point, $destinations)),
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
        ];
    }
}
