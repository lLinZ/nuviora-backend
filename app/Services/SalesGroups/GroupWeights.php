<?php

namespace App\Services\SalesGroups;

use Illuminate\Validation\ValidationException;

/**
 * Reglas de la lista de % de un grupo, con la Líder incluida (Fran, 2026-09-26). La usan el Admin
 * (Grupos de venta) y la Líder (Mi grupo). Tres formas válidas:
 * - todo vacío: parejo, la Líder recibe como una vendedora;
 * - solo la Líder con %: las vendedoras se reparten parejo lo que queda;
 * - todas con %: tienen que sumar 100.
 */
final class GroupWeights
{
    /**
     * @param  array<int, ?float>  $sellers  user_id => % de cada vendedora (null = vacío).
     * @param  string  $leaderMissing  mensaje cuando las vendedoras tienen % y la Líder no.
     */
    public static function validate(bool $hasLeader, ?float $leaderPct, array $sellers, string $leaderMissing): void
    {
        $filled = array_filter($sellers, fn ($w) => $w !== null);

        if ($filled !== [] && count($filled) !== count($sellers)) {
            self::fail('Pon el % de todas las vendedoras, o déjalas todas vacías para que se repartan parejo.');
        }
        if ($filled !== []) {
            if ($hasLeader && $leaderPct === null) {
                self::fail($leaderMissing);
            }
            $total = round(array_sum($filled) + ($leaderPct ?? 0), 2);
            if (abs($total - 100) > 0.01) {
                $diff = self::percent(abs(100 - $total));
                self::fail('Los % del grupo tienen que sumar 100. Ahora suman ' . self::percent($total) . ($total > 100 ? ", sobran {$diff}." : ", faltan {$diff}."));
            }
        } elseif ($sellers !== [] && $leaderPct !== null && $leaderPct >= 100) {
            self::fail('Si la Líder recibe el 100 %, pon 0 % a las vendedoras.');
        }
    }

    /** 120 → "120 %", 99.5 → "99,5 %". */
    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . ' %';
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['weights' => $message]);
    }
}
