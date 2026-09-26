<?php

namespace App\Services\Assignment\Weighted;

/**
 * Pesos efectivos del reparto por grupos, el "modelo de porciones" (HANDOFF §4, tarea 7).
 *
 * - Cada vendedora disponible aporta 1 porción a su grupo, así que un grupo con 7 disponibles
 *   recibe 7 veces lo de una sola.
 * - Las vendedoras se reparten esas porciones con sus %. Si ninguna tiene %, partes iguales.
 *   Una vendedora sin % mientras las demás sí lo tienen (recién agregada) recibe como el promedio.
 * - La Líder recibe su % de todo lo que llega al grupo (lo fija el Admin, Fran 2026-09-26). Su parte se
 *   suma encima de las porciones, para que sus vendedoras no reciban menos que las de otro grupo.
 *   Sin %, recibe como una vendedora.
 * - Las vendedoras sin grupo valen 1 cada una: sin grupos configurados, el reparto es parejo.
 */
final class EffectiveWeights
{
    /**
     * @param  array<int, array{group: ?int, leader: bool, weight: ?float}>  $candidates  solo las disponibles.
     * @return array<int, float>  user_id => peso efectivo (> 0).
     */
    public static function compute(array $candidates): array
    {
        $out = [];
        $groups = [];

        foreach ($candidates as $id => $c) {
            $weight = $c['weight'] === null ? null : (float) $c['weight'];
            if ($c['group'] === null) {
                $out[$id] = 1.0;
            } elseif ($c['leader']) {
                $groups[$c['group']]['leader'] = [$id, $weight];
            } else {
                $groups[$c['group']]['sellers'][$id] = $weight;
            }
        }

        foreach ($groups as $group) {
            $sellers = $group['sellers'] ?? [];
            $set = array_filter($sellers, fn ($w) => $w !== null);
            $average = $set === [] ? 1.0 : array_sum($set) / count($set);
            $shares = array_map(fn ($w) => $w ?? $average, $sellers);
            $sum = array_sum($shares);
            $portions = count($sellers);

            if ($sum > 0) {
                foreach ($shares as $id => $share) {
                    if ($share > 0) {
                        $out[$id] = $portions * $share / $sum;
                    }
                }
            }

            if (isset($group['leader'])) {
                [$id, $pct] = $group['leader'];
                $weight = match (true) {
                    $pct === null => 1.0,
                    $pct <= 0 => 0.0,
                    // Sin vendedoras que reciban (no vinieron o están en 0 %): el grupo es ella.
                    $sum <= 0 || $pct >= 100 => (float) max($portions, 1),
                    default => $portions * $pct / (100 - $pct),
                };
                if ($weight > 0) {
                    $out[$id] = $weight;
                }
            }
        }

        ksort($out);

        return $out;
    }
}
