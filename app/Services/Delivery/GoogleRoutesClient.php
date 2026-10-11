<?php

namespace App\Services\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Distancia por carretera con Google Routes API, Compute Routes (Módulo 3 de Fran, §3 y §4):
 * - POST https://routes.googleapis.com/directions/v2:computeRoutes, autenticado con X-Goog-Api-Key (§3);
 * - DRIVE y TRAFFIC_UNAWARE: una ruta estable, sin el tráfico del momento (§4);
 * - sin rutas alternativas y sin evitar peajes, autopistas ni ferris: la ruta normal recomendada (§4);
 * - unidades métricas, y X-Goog-FieldMask con solo routes.distanceMeters, nunca "*" (§4).
 * La clave sale del .env del servidor y no se escribe en ningún log ni respuesta (§44, §45).
 */
class GoogleRoutesClient
{
    public const URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';
    public const FIELD_MASK = 'routes.distanceMeters';

    public function configured(): bool
    {
        return (string) config('services.google_routes.key') !== '';
    }

    /** Metros por carretera del origen al destino. */
    public function distanceMeters(float $originLat, float $originLng, float $destLat, float $destLng): int
    {
        $key = (string) config('services.google_routes.key');
        if ($key === '') {
            throw new GoogleRoutesException(GoogleRoutesException::CONFIG, 'Falta la clave de Google Routes en el servidor (GOOGLE_ROUTES_API_KEY).');
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => self::FIELD_MASK])
                ->post(self::URL, self::body($originLat, $originLng, $destLat, $destLng));
        } catch (ConnectionException) {
            throw new GoogleRoutesException(GoogleRoutesException::NETWORK, 'Google Routes no respondió.');
        }

        if ($response->failed()) {
            $status = $response->status();
            $reason = (string) ($response->json('error.status') ?? '');
            $kind = match (true) {
                $status === 401 || $status === 403 || $reason === 'PERMISSION_DENIED' || $reason === 'UNAUTHENTICATED' => GoogleRoutesException::AUTH,
                $status === 429 || $reason === 'RESOURCE_EXHAUSTED' => GoogleRoutesException::QUOTA,
                $status >= 500 => GoogleRoutesException::NETWORK,
                default => GoogleRoutesException::REQUEST,
            };
            // El mensaje de Google no trae la clave, pero por si acaso se corta y se limpia
            $message = str_replace($key, '***', mb_substr((string) ($response->json('error.message') ?? ''), 0, 160));
            throw new GoogleRoutesException($kind, "Google Routes respondió {$status}" . ($reason ? " ({$reason})" : '') . ($message ? ": {$message}" : '.'), $status);
        }

        $routes = $response->json('routes');
        if (!is_array($routes) || $routes === []) {
            throw new GoogleRoutesException(GoogleRoutesException::NO_ROUTE, 'Google no encontró una ruta por carretera entre el almacén y el cliente.');
        }
        // En JSON de Google un 0 no se manda: sin el campo, la distancia es 0 (mismo punto)
        return (int) ($routes[0]['distanceMeters'] ?? 0);
    }

    /** El cuerpo de la petición, como lo pide el §4. */
    public static function body(float $originLat, float $originLng, float $destLat, float $destLng): array
    {
        return [
            'origin' => ['location' => ['latLng' => ['latitude' => $originLat, 'longitude' => $originLng]]],
            'destination' => ['location' => ['latLng' => ['latitude' => $destLat, 'longitude' => $destLng]]],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
            'computeAlternativeRoutes' => false,
            'routeModifiers' => ['avoidTolls' => false, 'avoidHighways' => false, 'avoidFerries' => false],
            'units' => 'METRIC',
        ];
    }
}
