<?php

namespace App\Services\Payments;

use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Las huellas del archivo de un comprobante (pedido de Fran del 2026-10-09):
 *  - SHA-256 del archivo original: si dos son iguales, es el mismo archivo exacto (determinista).
 *  - dHash de la imagen (256 bits, en una cuadrícula de 16×16 para que dos capturas del mismo banco con datos
 *    distintos no den la misma huella): la misma captura recomprimida o reenviada por WhatsApp da una huella muy
 *    parecida. Es solo un indicio, así que nunca bloquea.
 */
class ReceiptFingerprint
{
    /** Hasta cuántos bits de diferencia (de 256) dos huellas se consideran "la misma captura". */
    public const NEAR_BITS = 16;

    private const GRID = 16;

    /** Una imagen más chica que esto no es una captura de un comprobante: no se le saca huella. */
    private const MIN_SIDE = 64;

    /** Calcula y guarda las huellas si faltan. Devuelve el comprobante con ellas (o sin ellas si no está el archivo). */
    public function ensure(PaymentReceipt $receipt): PaymentReceipt
    {
        if ($receipt->file_sha256 && ($receipt->image_dhash || !$this->gdAvailable())) {
            return $receipt;
        }
        $disk = Storage::disk('public');
        if (!$receipt->path || !$disk->exists($receipt->path)) {
            return $receipt;
        }
        $bytes = $disk->get($receipt->path);
        $receipt->forceFill([
            'file_sha256' => hash('sha256', $bytes),
            'image_dhash' => $receipt->image_dhash ?? self::dhash($bytes),
        ]);
        if ($receipt->isDirty()) {
            $receipt->saveQuietly();
        }

        return $receipt;
    }

    /** dHash: la imagen a 17×16 en grises, y cada bit dice si un punto es más claro que el de su derecha. */
    public static function dhash(string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $img = @imagecreatefromstring($bytes);
            if (!$img || imagesx($img) < self::MIN_SIDE || imagesy($img) < self::MIN_SIDE) {
                return null;
            }
            $n = self::GRID;
            $small = imagecreatetruecolor($n + 1, $n);
            imagecopyresampled($small, $img, 0, 0, 0, 0, $n + 1, $n, imagesx($img), imagesy($img));
            $bits = '';
            for ($y = 0; $y < $n; $y++) {
                $prev = null;
                for ($x = 0; $x <= $n; $x++) {
                    $rgb = imagecolorat($small, $x, $y);
                    $gray = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
                    if ($prev !== null) {
                        $bits .= $prev > $gray ? '1' : '0';
                    }
                    $prev = $gray;
                }
            }

            return implode('', array_map(fn ($nibble) => dechex(bindec($nibble)), str_split($bits, 4)));
        } catch (Throwable) {
            return null;
        }
    }

    /** Bits distintos entre dos huellas. */
    public static function distance(string $a, string $b): int
    {
        $d = 0;
        foreach (str_split($a) as $i => $ch) {
            $d += substr_count(decbin(hexdec($ch) ^ hexdec($b[$i] ?? '0')), '1');
        }

        return $d;
    }

    private function gdAvailable(): bool
    {
        return function_exists('imagecreatefromstring');
    }
}
