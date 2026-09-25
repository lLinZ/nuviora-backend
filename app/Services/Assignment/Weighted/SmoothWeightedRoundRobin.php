<?php

namespace App\Services\Assignment\Weighted;

/**
 * Smooth Weighted Round Robin (el algoritmo de nginx), sin estado propio.
 *
 * En cada turno se suma a cada participante su peso, se elige al de mayor saldo y a ese se le
 * resta el total. Así un 70/30 sale A A B A A B A A B A en lugar de siete A seguidas, y cada
 * orden se asigna en cuanto entra, sin esperar a completar ningún bloque (regla de Fran §12–13).
 */
final class SmoothWeightedRoundRobin
{
    /**
     * @param  array<int, float>  $weights  id => peso de los participantes elegibles en este turno.
     * @param  array<int, float>  $current  saldo que quedó del turno anterior.
     * @return array{0: int, 1: array<int, float>}  el elegido y el saldo nuevo.
     *
     * Los pesos se normalizan (suman 1), así que el saldo se lee en "órdenes de ventaja". Quien no
     * está en $weights pierde su saldo: al volver entra desde 0, sin compensación (Fran §17 y §24).
     */
    public static function pick(array $weights, array $current): array
    {
        $weights = array_filter($weights, fn ($w) => $w > 0);
        if ($weights === []) {
            throw new \InvalidArgumentException('No hay participantes con peso');
        }

        ksort($weights); // desempate estable: gana el ID menor
        $total = array_sum($weights);

        $next = [];
        $picked = null;
        foreach ($weights as $id => $weight) {
            $next[$id] = ($current[$id] ?? 0.0) + $weight / $total;
            if ($picked === null || $next[$id] > $next[$picked] + 1e-9) {
                $picked = $id;
            }
        }
        $next[$picked] -= 1.0;

        return [$picked, array_map(fn ($v) => round($v, 6), $next)];
    }
}
