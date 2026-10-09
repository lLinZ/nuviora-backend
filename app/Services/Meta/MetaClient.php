<?php

namespace App\Services\Meta;

use App\Models\MetaConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la Marketing API de Meta (documento de Fran del 2026-10-08, Módulo 2).
 *
 * - Solo lee: no tiene ningún método para crear, editar ni borrar nada en Meta (§1: "100 % READ ONLY").
 * - El token va en la cabecera Authorization, nunca en la URL, y se quita de los enlaces de paginación que devuelve
 *   Meta. Ningún mensaje de error lo lleva (§4).
 * - La versión de la API sale de la configuración (META_GRAPH_API_VERSION, §53).
 * - Guarda las cabeceras de uso de Meta para frenar antes del límite (§52).
 */
final class MetaClient
{
    private const BASE = 'https://graph.facebook.com';
    private const USAGE_HEADERS = ['x-business-use-case-usage', 'x-ad-account-usage', 'x-fb-ads-insights-throttle', 'x-app-usage'];

    private string $version;
    private int $calls = 0;
    private array $usage = [];

    public function __construct(private readonly string $token, private readonly ?string $appSecret = null, ?string $version = null)
    {
        $this->version = $version ?: (string) config('services.meta.graph_version', 'v26.0');
    }

    public static function for(MetaConnection $connection): self
    {
        return new self((string) $connection->access_token, $connection->app_secret ?: null);
    }

    /** GET de un objeto o una lista (solo la primera página). */
    public function get(string $path, array $params = []): array
    {
        return $this->request(self::BASE . '/' . $this->version . '/' . ltrim($path, '/'), $this->encode($params));
    }

    /**
     * GET de una lista completa, siguiendo paging.next.
     *
     * @return array las filas de "data" de todas las páginas
     */
    public function getAll(string $path, array $params = [], int $maxPages = 500): array
    {
        $page = $this->get($path, $params);
        $rows = $page['data'] ?? [];
        $pages = 1;
        while (!empty($page['paging']['next']) && $pages < $maxPages) {
            [$url, $query] = $this->splitNext((string) $page['paging']['next']);
            $page = $this->request($url, $query);
            $rows = array_merge($rows, $page['data'] ?? []);
            $pages++;
        }
        return $rows;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** La última lectura de cada cabecera de uso, ya decodificada. */
    public function usage(): array
    {
        return $this->usage;
    }

    /** El mayor % de uso que informó Meta en la última respuesta (0 a 100). */
    public function usagePercent(): float
    {
        $max = 0.0;
        array_walk_recursive($this->usage, function ($value, $key) use (&$max) {
            if (in_array($key, ['call_count', 'total_cputime', 'total_time', 'acc_id_util_pct', 'app_id_util_pct'], true) && is_numeric($value)) {
                $max = max($max, (float) $value);
            }
        });
        return $max;
    }

    /** Minutos que Meta pide esperar antes de volver a llamar (0 si no pide nada). */
    public function minutesToRegainAccess(): int
    {
        $max = 0;
        array_walk_recursive($this->usage, function ($value, $key) use (&$max) {
            if (!is_numeric($value)) {
                return;
            }
            if ($key === 'estimated_time_to_regain_access') {      // en minutos
                $max = max($max, (int) ceil((float) $value));
            } elseif ($key === 'reset_time_duration') {            // en segundos
                $max = max($max, (int) ceil((float) $value / 60));
            }
        });
        return $max;
    }

    private function request(string $url, array $query): array
    {
        $proof = $this->appSecret ? hash_hmac('sha256', $this->token, $this->appSecret) : null;
        if ($proof) {
            $query['appsecret_proof'] = $proof;
        }
        $this->calls++;

        try {
            $response = Http::withToken($this->token)->acceptJson()->connectTimeout(10)->timeout(50)->get($url, $query);
        } catch (ConnectionException $e) {
            throw new MetaApiException(MetaApiException::TIMEOUT, MetaApiException::clean($e->getMessage(), [$this->token, $proof]));
        }

        $this->readUsage($response);

        if ($response->failed()) {
            $error = $response->json('error');
            if (!is_array($error)) {
                $error = ['message' => 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300)];
            }
            throw MetaApiException::fromError($response->status(), $error, [$this->token, $proof]);
        }

        $json = $response->json();
        return is_array($json) ? $json : [];
    }

    /** Las listas y objetos van como JSON, como los pide la Graph API. */
    private function encode(array $params): array
    {
        return array_map(fn ($v) => is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : $v), $params);
    }

    /** Separa el enlace paging.next en URL y parámetros, sin el token ni el appsecret_proof que pueda traer. */
    private function splitNext(string $next): array
    {
        $parts = parse_url($next);
        parse_str($parts['query'] ?? '', $query);
        unset($query['access_token'], $query['appsecret_proof']);
        $url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'graph.facebook.com') . ($parts['path'] ?? '');
        return [$url, $query];
    }

    private function readUsage(Response $response): void
    {
        foreach (self::USAGE_HEADERS as $header) {
            $value = $response->header($header);
            if ($value !== '') {
                $decoded = json_decode($value, true);
                $this->usage[$header] = is_array($decoded) ? $decoded : $value;
            }
        }
    }
}
