<?php

namespace App\Services\Delivery\Closures;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Normalización antes de emparejar (Módulo 3 de Fran, §26). Solo produce claves para comparar: lo que traía el archivo
 * se guarda aparte, tal cual ("sin eliminar información de forma destructiva").
 */
class ClosureNormalizer
{
    /**
     * El número de orden que escribe la agencia: "###54563", "##4617", "#MAN-27114", "54566", "#4747 (1)".
     * Devuelve la clave ("54563", "MAN-27114") y si la agencia la marcó como repetida con "(1)".
     * @return array{key: ?string, repeat: bool}
     */
    public static function orderKey(?string $raw): array
    {
        $text = trim((string) $raw);
        $repeat = (bool) preg_match('/\(\s*\d+\s*\)\s*$/', $text);
        $text = preg_replace('/\(\s*\d+\s*\)\s*$/', '', $text);
        $key = strtoupper(preg_replace('/[\s#°º.]+/u', '', $text));
        $key = preg_replace('/^MAN-?(\d+)$/', 'MAN-$1', $key);
        if (preg_match('/^\d+\.0+$/', $key)) {
            $key = preg_replace('/\.0+$/', '', $key); // un número de Excel: 54563.0
        }

        return ['key' => preg_match('/\d/', $key) && strlen($key) <= 40 ? $key : null, 'repeat' => $repeat];
    }

    /** Las claves con que se reconoce una orden del sistema: su nombre ("#54340" → "54340", "MAN-338") y su número. */
    public static function orderKeysFor(?string $name, $orderNumber): array
    {
        $keys = [];
        if ($name !== null && ($k = self::orderKey($name)['key'])) {
            $keys[] = $k;
        }
        if ($orderNumber !== null && $orderNumber !== '' && preg_match('/^\d+$/', (string) $orderNumber)) {
            $keys[] = (string) $orderNumber;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Teléfono venezolano a la forma local de 11 dígitos: "0414-555.12.34", "(0414) 5551234", "+58 414 5551234",
     * "584145551234" y "4145551234" → "04145551234". Si no parece un teléfono, null.
     */
    public static function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);
        if (strlen($digits) === 12 && str_starts_with($digits, '58')) {
            $digits = '0' . substr($digits, 2);
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '580')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 10 && in_array($digits[0], ['2', '4'], true)) {
            $digits = '0' . $digits;
        }

        return strlen($digits) === 11 && $digits[0] === '0' ? $digits : (strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null);
    }

    /** Un teléfono escrito dentro de otro texto ("La Quinta/ terminal de maracay 4243609501"). */
    public static function phoneIn(?string $text): ?string
    {
        if (preg_match('/(?:\+?58[\s.-]?)?\(?0?(?:4\d{2}|2\d{2})\)?[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}/', (string) $text, $m)) {
            $phone = self::phone($m[0]);
            return $phone !== null && strlen($phone) === 11 ? $phone : null;
        }

        return null;
    }

    /** Nombre para comparar: minúsculas, sin tildes, sin signos y sin espacios dobles ("MARÍA  José." → "maria jose"). */
    public static function name(?string $raw): ?string
    {
        $text = Str::lower(Str::ascii((string) $raw));
        $text = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z\s]/', ' ', $text)));

        return $text === '' ? null : $text;
    }

    /** Las palabras de un nombre que sirven para comparar (sin "de", "la", "del"…). */
    public static function nameTokens(?string $key): array
    {
        $stop = ['de', 'del', 'la', 'las', 'los', 'y', 'el', 'sr', 'sra', 'srta'];

        return array_values(array_filter(explode(' ', (string) $key), fn ($t) => strlen($t) >= 2 && !in_array($t, $stop, true)));
    }

    /**
     * Cuánto se parece el nombre del archivo al del cliente: la parte de las palabras del archivo que están en el del
     * cliente (0 a 1). La primera palabra tiene que estar; se admiten los errores de tipeo comunes en palabras de 4 letras
     * o más ("jaun" por "juan", "gonzales" por "gonzalez").
     */
    public static function nameScore(?string $rowKey, ?string $clientKey): float
    {
        $row = self::nameTokens($rowKey);
        $client = self::nameTokens($clientKey);
        if (!$row || !$client) {
            return 0.0;
        }
        $found = 0;
        foreach ($row as $i => $token) {
            $hit = false;
            foreach ($client as $c) {
                if (self::closeWords($token, $c)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit && $i === 0) {
                return 0.0;
            }
            $found += $hit ? 1 : 0;
        }

        return $found / count($row);
    }

    /** Igual, o una letra de diferencia, o dos letras cambiadas de sitio ("jaun" por "juan"), en palabras de 4 o más. */
    private static function closeWords(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (strlen($a) < 4 || strlen($b) < 4) {
            return false;
        }
        $distance = levenshtein($a, $b);

        return $distance <= 1 || ($distance === 2 && strlen($a) === strlen($b) && count_chars($a, 1) === count_chars($b, 1));
    }

    private const MONTHS = [
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
        'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    /**
     * Fecha escrita por la agencia: "2026-10-03", "1/10/26", "29/9/26", "28-sept-2026", "3 oct 2026". Día primero, como
     * en Venezuela. Null si no se entiende.
     */
    public static function date(?string $raw): ?string
    {
        $text = Str::lower(Str::ascii(trim((string) $raw)));
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $text, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})\b/', $text, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\s\-\/.]*(?:de\s+)?([a-z]{3})[a-z]*\.?[\s\-\/.]*(?:de\s+)?(\d{2,4})\b/', $text, $m) && isset(self::MONTHS[$m[2]])) {
            [$d, $mo, $y] = [(int) $m[1], self::MONTHS[$m[2]], (int) $m[3]];
        } else {
            return null;
        }
        $y = $y < 100 ? 2000 + $y : $y;

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Días entre dos fechas (sin signo). */
    public static function daysApart(?string $a, ?string $b): ?int
    {
        return $a && $b ? (int) abs(Carbon::parse($a)->diffInDays(Carbon::parse($b))) : null;
    }
}
