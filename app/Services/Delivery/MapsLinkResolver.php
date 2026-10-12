<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Http;

/**
 * Saca las coordenadas de un link de Google Maps (Módulo 3 de Fran, §7: "Maps URL → resolver ubicación → guardar
 * coordenadas"). Sin APIs de Google ("No activar nuevas APIs de Google innecesariamente"):
 * - si la URL ya trae las coordenadas, se leen de ahí: el punto exacto (!3d…!4d…), ?q=lat,lng, /search/lat,lng o el
 *   centro del mapa (@lat,lng);
 * - si es un enlace corto (maps.app.goo.gl), se sigue la redirección y se lee la URL larga;
 * - si es un lugar o una búsqueda sin coordenadas, queda "pendiente de resolver", y si no es de Maps, "inválida".
 */
class MapsLinkResolver
{
    public const OK = 'ok';
    public const PENDING = 'pending';
    public const INVALID = 'invalid';

    /** Hasta dónde llega Venezuela, con margen: lo de afuera es casi siempre un error (lat y lng al revés, 0,0). */
    private const BOUNDS = ['lat' => [0.5, 13.5], 'lng' => [-74.5, -59.0]];

    private const SHORT_HOSTS = '~^https?://(maps\.app\.goo\.gl|goo\.gl/maps|g\.co/kgs)/~i';

    /** Si el texto es (o trae) un enlace corto, que hay que abrir para ver las coordenadas. */
    public static function isShortLink(?string $text): bool
    {
        return (bool) preg_match('~https?://(maps\.app\.goo\.gl|goo\.gl/maps|g\.co/kgs)/~i', (string) $text);
    }

    /**
     * $near: el punto de referencia para un código plus corto (el almacén de la agencia de esa ciudad).
     * @return array{status: string, lat: ?float, lng: ?float, note: ?string}
     */
    public function resolve(?string $text, bool $followShortLinks = true, ?array $near = null): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return $this->result(self::INVALID, null, null, 'No hay ubicación.');
        }
        // Solo las coordenadas ("10.48, -66.88")
        if (preg_match('~^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$~', $text, $m)) {
            return $this->checked((float) $m[1], (float) $m[2], null);
        }
        if (!preg_match('~https?://\S+~i', $text, $m)) {
            return $this->plusCode($text, $near) ?? $this->result(self::INVALID, null, null, 'No es un link de Google Maps.');
        }
        $url = rtrim($m[0], '.,;)');

        if (preg_match(self::SHORT_HOSTS, $url)) {
            if (!$followShortLinks) {
                return $this->result(self::PENDING, null, null, 'Enlace corto: falta seguir la redirección.');
            }
            $long = $this->follow($url);
            if ($long === null) {
                return $this->result(self::PENDING, null, null, 'No se pudo abrir el enlace corto. Se reintenta.');
            }
            $url = $long;
        }

        if (!preg_match('~google\.[a-z.]+/maps|maps\.google\.|google\.[a-z.]+/\?q=|maps\.apple\.com~i', $url)) {
            return $this->result(self::INVALID, null, null, 'No es un link de Google Maps.');
        }
        $coords = self::coordinatesIn($url);
        if ($coords === null) {
            // Un lugar sin nombre: Google lo llama por su código plus ("8C6F+QX Caracas")
            return $this->plusCode(self::placeText($url), $near)
                ?? $this->result(self::PENDING, null, null, 'El link es de un lugar o una búsqueda, sin coordenadas: pide al cliente que comparta su ubicación.');
        }

        return $this->checked($coords[0], $coords[1], $coords[2]);
    }

    /**
     * Un código plus de Google (2026-10-12: así llegaron 46 de las 110 ubicaciones sin leer en producción). El completo
     * se lee solo; el corto necesita un punto a menos de medio grado, el almacén de la agencia de esa ciudad.
     */
    private function plusCode(string $text, ?array $near): ?array
    {
        $code = PlusCode::find($text);
        if ($code === null) {
            return null;
        }
        if (PlusCode::isFull($code)) {
            $point = PlusCode::decode($code);
        } elseif ($near) {
            $point = PlusCode::recoverNearest($code, (float) $near[0], (float) $near[1]);
        } else {
            return $this->result(self::PENDING, null, null, 'Código plus sin un almacén de referencia: se lee cuando el almacén de la agencia de la ciudad tenga sus coordenadas.');
        }

        return $point ? $this->checked($point[0], $point[1], 'Código plus de Google.') : null;
    }

    /** El nombre del lugar o la búsqueda de un link de Maps (/maps/place/<nombre>/, ?q=<nombre>), ya decodificado. */
    private static function placeText(string $url): string
    {
        $parts = [];
        if (preg_match('~/maps/(?:place|search)/([^/?#]+)~', $url, $m)) {
            $parts[] = urldecode($m[1]);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (is_string($query['q'] ?? null)) {
            $parts[] = $query['q'];
        }
        return implode(' ', $parts);
    }

    /**
     * Las coordenadas que trae una URL, en orden de precisión: el punto marcado, las de la consulta y, por último, el
     * centro del mapa. @return array{0: float, 1: float, 2: ?string}|null la tercera es una nota si es aproximada
     */
    public static function coordinatesIn(string $url): ?array
    {
        $url = urldecode($url);
        $n = '(-?\d{1,3}\.\d+)';
        if (preg_match("~!3d{$n}!4d{$n}~", $url, $m)) {
            return [(float) $m[1], (float) $m[2], null];
        }
        if (preg_match("~[?&](?:q|query|ll|destination|daddr|center)=(?:loc:)?\s*{$n}\s*,\s*\+?{$n}~i", $url, $m)) {
            return [(float) $m[1], (float) $m[2], null];
        }
        if (preg_match("~/(?:search|place|dir/[^/]*)/\+?{$n}\s*,\s*\+?{$n}~i", $url, $m)) {
            return [(float) $m[1], (float) $m[2], null];
        }
        if (preg_match("~@{$n},{$n}~", $url, $m)) {
            return [(float) $m[1], (float) $m[2], 'Centro del mapa (el link no trae un punto marcado).'];
        }
        return null;
    }

    /** Sigue las redirecciones del enlace corto (sin descargar la página) hasta la URL de Maps. */
    private function follow(string $url): ?string
    {
        try {
            for ($hops = 0; $hops < 5; $hops++) {
                $response = Http::withoutRedirecting()->timeout(10)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url);
                $location = $response->header('Location');
                if (!$response->redirect() || !$location) {
                    return $hops > 0 ? $url : null;
                }
                $url = str_starts_with($location, 'http') ? $location : (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . $location);
                if (self::coordinatesIn($url) !== null) {
                    return $url;
                }
            }
            return $url;
        } catch (\Throwable) {
            return null;
        }
    }

    private function checked(float $lat, float $lng, ?string $note): array
    {
        if ($lat < self::BOUNDS['lat'][0] || $lat > self::BOUNDS['lat'][1] || $lng < self::BOUNDS['lng'][0] || $lng > self::BOUNDS['lng'][1]) {
            return $this->result(self::INVALID, $lat, $lng, 'Las coordenadas quedan fuera de Venezuela.');
        }
        return $this->result(self::OK, round($lat, 7), round($lng, 7), $note);
    }

    private function result(string $status, ?float $lat, ?float $lng, ?string $note): array
    {
        return ['status' => $status, 'lat' => $status === self::OK ? $lat : null, 'lng' => $status === self::OK ? $lng : null, 'note' => $note];
    }
}
