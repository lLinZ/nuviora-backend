<?php

namespace App\Services\Delivery;

use RuntimeException;

/** Un fallo de Google Routes, ya clasificado. El mensaje nunca lleva la clave (Módulo 3, §44). */
class GoogleRoutesException extends RuntimeException
{
    public const CONFIG = 'config';     // falta la clave
    public const AUTH = 'auth';         // clave inválida o sin permiso para Routes API
    public const QUOTA = 'quota';       // límite de uso
    public const NO_ROUTE = 'no_route'; // Google no encuentra ruta por carretera
    public const REQUEST = 'request';   // petición rechazada (coordenadas inválidas…)
    public const NETWORK = 'network';   // sin respuesta o error del servidor de Google

    public function __construct(public readonly string $kind, string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    /** Si vale la pena reintentar sola (§42: "reintento automático"). Una clave mala no se arregla reintentando. */
    public function retryable(): bool
    {
        return in_array($this->kind, [self::QUOTA, self::NETWORK], true);
    }
}
