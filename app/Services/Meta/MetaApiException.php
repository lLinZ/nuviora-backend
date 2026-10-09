<?php

namespace App\Services\Meta;

/**
 * Un error de la Marketing API, ya clasificado (documento de Fran del 2026-10-08, Módulo 2, §54: token inválido,
 * token revocado, cuenta sin permiso, objeto eliminado, rate limit, timeout, error de Meta, campo no disponible).
 * El mensaje nunca lleva el token: se borra antes de crear la excepción (§4).
 */
class MetaApiException extends \RuntimeException
{
    public const TOKEN = 'token';                 // token inválido, vencido o revocado
    public const PERMISSION = 'permission';       // sin permiso sobre la cuenta, el objeto o el campo
    public const NOT_FOUND = 'not_found';         // objeto eliminado o inaccesible
    public const RATE_LIMIT = 'rate_limit';       // límite de llamadas de Meta
    public const FIELD = 'field';                 // campo no disponible
    public const VERSION = 'version';             // versión de la API sin soporte (2635)
    public const TOO_MUCH_DATA = 'too_much_data'; // la consulta pide demasiados datos de una vez
    public const TIMEOUT = 'timeout';             // Meta no respondió o se cortó la conexión
    public const META = 'meta';                   // error de Meta, temporal o desconocido

    private const LABELS = [
        self::TOKEN => 'Token inválido, vencido o revocado',
        self::PERMISSION => 'Sin permiso',
        self::NOT_FOUND => 'Objeto eliminado o inaccesible',
        self::RATE_LIMIT => 'Límite de llamadas de Meta',
        self::FIELD => 'Campo no disponible',
        self::VERSION => 'Versión de la API sin soporte',
        self::TOO_MUCH_DATA => 'Demasiados datos en una consulta',
        self::TIMEOUT => 'Meta no respondió a tiempo',
        self::META => 'Error de Meta',
    ];

    /** Códigos de límite de llamadas de la Graph y la Marketing API. */
    private const RATE_LIMIT_CODES = [4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $metaCode = null,
        public readonly ?int $subcode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $fbtraceId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param array $error el objeto "error" que devuelve Meta
     * @param array $secrets textos que nunca deben quedar en el mensaje (el token, el appsecret_proof)
     */
    public static function fromError(int $httpStatus, array $error, array $secrets = []): self
    {
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $message = self::clean((string) ($error['message'] ?? "HTTP {$httpStatus}"), $secrets);

        return new self(self::classify($code, $subcode, $message, $httpStatus), $message, $code, $subcode, $httpStatus,
            isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null);
    }

    public static function classify(?int $code, ?int $subcode, string $message, int $httpStatus = 400): string
    {
        $m = strtolower($message);
        if ($code === 190 || $code === 102) {
            return self::TOKEN;
        }
        if ($code === 2635) {
            return self::VERSION;
        }
        if (in_array($code, self::RATE_LIMIT_CODES, true) || in_array($subcode, [1504022, 1504039, 2446079], true)) {
            return self::RATE_LIMIT;
        }
        if (str_contains($m, 'reduce the amount of data')) {
            return self::TOO_MUCH_DATA;
        }
        if ($code === 10 || $code === 3 || ($code !== null && $code >= 200 && $code <= 299)) {
            return self::PERMISSION;
        }
        if ($code === 100) {
            if ($subcode === 33 || str_contains($m, 'does not exist')) {
                return self::NOT_FOUND;
            }
            if (str_contains($m, 'permission')) {
                return self::PERMISSION;
            }
            if (str_contains($m, 'is not valid for fields param') || str_contains($m, 'nonexisting field')) {
                return self::FIELD;
            }
        }
        return self::META;
    }

    public static function clean(string $text, array $secrets): string
    {
        foreach (array_filter($secrets) as $secret) {
            $text = str_replace($secret, '***', $text);
        }
        return mb_substr($text, 0, 1000);
    }

    /** Errores que se arreglan solos: se espera y se reintenta en la siguiente vuelta (§7). */
    public function isTransient(): bool
    {
        return in_array($this->kind, [self::RATE_LIMIT, self::TIMEOUT, self::META], true);
    }

    /** Errores que afectan a toda la conexión, no a una cuenta (§55: "Problema: Token/permisos"). */
    public function affectsConnection(): bool
    {
        return in_array($this->kind, [self::TOKEN, self::VERSION], true);
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? self::LABELS[self::META];
    }

    public static function labelFor(?string $kind): ?string
    {
        return $kind === null ? null : (self::LABELS[$kind] ?? self::LABELS[self::META]);
    }
}
