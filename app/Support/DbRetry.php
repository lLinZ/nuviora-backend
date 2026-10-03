<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Reintenta una operación cuando MariaDB la rechaza por un choque con otra que escribió lo mismo en
 * ese instante: 1020 "Record has changed since last read" (aislamiento de MariaDB 11.8), 1213
 * (deadlock) y 1205 (espera de bloqueo). Pasaba al abrir la tienda: un mensaje de WhatsApp o un pedido
 * de Shopify tocaba el mismo cliente o el mismo turno del reparto, y el reparto de la mañana se
 * detenía a mitad (30-sep, 16-sep y 18-sep en producción).
 */
final class DbRetry
{
    private const CONFLICT_CODES = [1020, 1205, 1213];

    /**
     * @template T
     * @param  callable(): T  $fn  tiene que poder repetirse: lo normal es que abra su propia transacción.
     * @return T
     */
    public static function onConflict(callable $fn, int $times = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                if ($attempt >= $times || !self::isConflict($e)) {
                    throw $e;
                }
                usleep(150_000 * $attempt);
            }
        }
    }

    public static function isConflict(\Throwable $e): bool
    {
        return $e instanceof QueryException && in_array((int) ($e->errorInfo[1] ?? 0), self::CONFLICT_CODES, true);
    }
}
