<?php

namespace App\Services\Assignment\Weighted;

/**
 * Pesos efectivos del reparto por grupos, el "modelo de porciones" (HANDOFF §4, tarea 7).
 *
 * - Cada vendedora disponible aporta 1 porción a su grupo, así que un grupo con 7 disponibles
 *   recibe 7 veces lo de una sola. La Líder aporta su carga (la fija el Admin, por ejemplo 0,65).
 * - La Líder reparte las porciones de sus vendedoras con sus %. Si nadie tiene %, partes iguales.
 *   Una vendedora sin % mientras las demás sí lo tienen (recién agregada) recibe como el promedio.
 * - Las vendedoras sin grupo valen 1 cada una: sin grupos configurados, el reparto es parejo.
 */
final class EffectiveWeights
{
    /**
     * @param  array<int, array{group: ?int, leader: bool, weight: ?float}>  $candidates  solo las disponibles.
     * @param  array<int, float>  $leaderLoads  group_id => carga de su Líder.
     * @return array<int, float>  user_id => peso efectivo (> 0).
     */
    public static function compute(array $candidates, array $leaderLoads): array
    {
        $out = [];
        $sellersByGroup = [];

        foreach ($candidates as $id => $c) {
            if ($c['group'] === null) {
                $out[$id] = 1.0;
            } elseif ($c['leader']) {
                $load = (float) ($leaderLoads[$c['group']] ?? 0);
                if ($load > 0) {
                    $out[$id] = $load;
                }
            } else {
                $sellersByGroup[$c['group']][$id] = $c['weight'] === null ? null : (float) $c['weight'];
            }
        }

        foreach ($sellersByGroup as $sellers) {
            $set = array_filter($sellers, fn ($w) => $w !== null);
            $average = $set === [] ? 1.0 : array_sum($set) / count($set);
            $shares = array_map(fn ($w) => $w ?? $average, $sellers);
            $sum = array_sum($shares);
            if ($sum <= 0) {
                continue; // la Líder puso a todas en 0 %: el grupo no recibe
            }
            $portions = count($sellers);
            foreach ($shares as $id => $share) {
                if ($share > 0) {
                    $out[$id] = $portions * $share / $sum;
                }
            }
        }

        ksort($out);

        return $out;
    }
}
