<?php

namespace App\Services\Meta;

use App\Models\User;

/**
 * Quién ve los datos financieros de Meta Ads (documento de Fran del 2026-10-08, Módulo 2, §44 y §45).
 *
 * Hoy solo entra el Admin, que ve todo (§44). El futuro Editor "NO debe poder ver: Spend; gasto total; rentabilidad;
 * información financiera", pero sí CPA, CTR, CPC, CPM, Frequency, Hook y Hold Rate, Target CPA y Break-even (§45). El
 * control va en el backend: las respuestas salen sin esos campos para quien no puede verlos ("No simplemente
 * ocultando el elemento en frontend"). El backend sí usa el gasto para las reglas (§45).
 */
class MetaVisibility
{
    /** Los campos que se quitan: el gasto, el valor de las compras (ingresos) y los totales de gasto. */
    public const FINANCIAL_KEYS = ['spend', 'purchase_value', 'spend_30d'];

    public static function canSeeFinancials(?User $user): bool
    {
        return $user?->role?->description === 'Admin';
    }

    public static function forUser(array $data, ?User $user): array
    {
        return self::canSeeFinancials($user) ? $data : self::strip($data);
    }

    public static function strip(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::FINANCIAL_KEYS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::strip($value);
            }
        }
        return $data;
    }
}
