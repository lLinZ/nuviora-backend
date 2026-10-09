<?php

namespace App\Services\Meta;

/**
 * Detecta el creative_tracking_id en el nombre de un anuncio (documento de Fran del 2026-10-08, Módulo 2, §32: "En
 * muchos casos el nombre del anuncio ya contiene este identificador. El sistema podrá intentar detectarlo mediante
 * nomenclatura"). Si no lo encuentra, queda vacío para ponerlo a mano.
 *
 * El código va al principio del nombre: letras y un número ("C004"), seguidos o no de partes separadas por guiones
 * ("C004-COMP-GINE-UGC-H1"). Se ignora lo que Meta agrega al duplicar (" - Copia", " – Copy 2").
 *
 * Modo "name" (por defecto, el ejemplo del §32): el ID es el código completo, "C004-COMP-GINE-UGC-H1".
 * Modo "prefix" (el "C004" del §33 y el §60): solo la primera parte, "C004".
 * Pendiente de la respuesta de Fran (pregunta 2 del plan): se cambia con META_TRACKING_ID_MODE.
 */
class CreativeTrackingId
{
    private const PATTERN = '/^\s*([A-Za-z]{1,4}\d{2,})((?:[-_][A-Za-z0-9]+)*)/';

    public static function detect(?string $adName, ?string $mode = null): ?string
    {
        $name = (string) $adName;
        // Lo que agrega Meta al duplicar no es parte del código
        $name = preg_replace('/\s+[-–—]\s*(copia|copy)(\s*\d+)?\s*$/iu', '', $name) ?? $name;
        if (!preg_match(self::PATTERN, $name, $m)) {
            return null;
        }
        $mode = $mode ?? (string) config('services.meta.tracking_id_mode', 'name');
        $id = $mode === 'prefix' ? $m[1] : $m[1] . $m[2];
        return strtoupper(mb_substr($id, 0, 120));
    }
}
