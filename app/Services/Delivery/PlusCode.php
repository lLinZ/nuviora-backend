<?php

namespace App\Services\Delivery;

/**
 * Códigos plus de Google (Open Location Code), sin internet. Cuando el cliente comparte un lugar sin nombre, Google lo
 * llama por su código: "8C6F+QX Caracas" (corto: le faltan los primeros dígitos, que salen de un punto cercano) o
 * "67Q38C6F+QX" (completo). Producción, 2026-10-12: 46 de las 110 ubicaciones sin leer eran códigos plus cortos.
 * Algoritmo de la especificación pública de Open Location Code (decode y recoverNearest).
 */
class PlusCode
{
    private const ALPHABET = '23456789CFGHJMPQRVWX';
    private const SEPARATOR_POSITION = 8;
    private const PAIR_RESOLUTIONS = [20.0, 1.0, 0.05, 0.0025, 0.000125];
    private const GRID_ROWS = 5;
    private const GRID_COLUMNS = 4;

    /** El primer código plus de un texto ("8C6F+QX Caracas" → "8C6F+QX"), o null. */
    public static function find(?string $text): ?string
    {
        if (!preg_match_all('~(?<![0-9A-Z])([23456789CFGHJMPQRVWX]{2,8}\+[23456789CFGHJMPQRVWX]{2,3})(?![0-9A-Z])~i', (string) $text, $m)) {
            return null;
        }
        foreach ($m[1] as $code) {
            $code = strtoupper($code);
            if (self::isFull($code) || self::isShort($code)) {
                return $code;
            }
        }
        return null;
    }

    public static function isFull(string $code): bool
    {
        if (!self::wellFormed($code) || strpos($code, '+') !== self::SEPARATOR_POSITION) {
            return false;
        }
        // El primer par no puede pasar de 90° de latitud ni de 180° de longitud
        return strpos(self::ALPHABET, $code[0]) * 20 < 180 && strpos(self::ALPHABET, $code[1]) * 20 < 360;
    }

    public static function isShort(string $code): bool
    {
        if (!self::wellFormed($code)) {
            return false;
        }
        $sep = strpos($code, '+');
        return $sep !== false && $sep < self::SEPARATOR_POSITION && $sep >= 2 && $sep % 2 === 0;
    }

    private static function wellFormed(string $code): bool
    {
        return (bool) preg_match('~^[23456789CFGHJMPQRVWX]+\+[23456789CFGHJMPQRVWX]{2,}$~', $code);
    }

    /** El centro de un código completo: [lat, lng], o null si no es válido. */
    public static function decode(string $code): ?array
    {
        $code = strtoupper($code);
        if (!self::isFull($code)) {
            return null;
        }
        $digits = str_replace('+', '', $code);
        $lat = -90.0;
        $lng = -180.0;
        $latSize = $lngSize = 0.0;
        $pairs = min(strlen($digits), 10);
        for ($i = 0; $i < $pairs; $i += 2) {
            $res = self::PAIR_RESOLUTIONS[intdiv($i, 2)];
            $lat += strpos(self::ALPHABET, $digits[$i]) * $res;
            $lng += strpos(self::ALPHABET, $digits[$i + 1]) * $res;
            $latSize = $lngSize = $res;
        }
        // Más de 10 dígitos: cada uno parte la celda en una cuadrícula de 5 filas por 4 columnas
        for ($i = 10; $i < strlen($digits); $i++) {
            $latSize /= self::GRID_ROWS;
            $lngSize /= self::GRID_COLUMNS;
            $index = strpos(self::ALPHABET, $digits[$i]);
            $lat += intdiv($index, self::GRID_COLUMNS) * $latSize;
            $lng += ($index % self::GRID_COLUMNS) * $lngSize;
        }

        return [round($lat + $latSize / 2, 7), round($lng + $lngSize / 2, 7)];
    }

    /** Un código corto, completado con el punto de referencia más cercano (recoverNearest): [lat, lng]. */
    public static function recoverNearest(string $short, float $refLat, float $refLng): ?array
    {
        $short = strtoupper($short);
        if (self::isFull($short)) {
            return self::decode($short);
        }
        if (!self::isShort($short)) {
            return null;
        }
        $padding = self::SEPARATOR_POSITION - strpos($short, '+');
        $resolution = 20 ** (2 - $padding / 2);
        $half = $resolution / 2;
        $center = self::decode(substr(self::encode($refLat, $refLng), 0, $padding) . $short);
        if ($center === null) {
            return null;
        }
        [$lat, $lng] = $center;
        if ($refLat + $half < $lat && $lat - $resolution >= -90) {
            $lat -= $resolution;
        } elseif ($refLat - $half > $lat && $lat + $resolution <= 90) {
            $lat += $resolution;
        }
        if ($refLng + $half < $lng) {
            $lng -= $resolution;
        } elseif ($refLng - $half > $lng) {
            $lng += $resolution;
        }

        return [round($lat, 7), round($lng, 7)];
    }

    /** El código de 10 dígitos de un punto ("67Q38C6F+QX"). */
    public static function encode(float $lat, float $lng): string
    {
        $lat = min(max($lat, -90.0), 89.9999999) + 90;
        $lng = fmod(fmod($lng + 180, 360) + 360, 360);
        $code = '';
        foreach (self::PAIR_RESOLUTIONS as $res) {
            $d = min(19, (int) floor($lat / $res + 1e-9));
            $lat -= $d * $res;
            $code .= self::ALPHABET[$d];
            $d = min(19, (int) floor($lng / $res + 1e-9));
            $lng -= $d * $res;
            $code .= self::ALPHABET[$d];
            if (strlen($code) === self::SEPARATOR_POSITION) {
                $code .= '+';
            }
        }
        return $code;
    }
}
