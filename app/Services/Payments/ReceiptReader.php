<?php

namespace App\Services\Payments;

use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Lee un comprobante de pago con IA (Fran, 2026-10-03): gpt-6-luna por la Responses API, con la imagen y un
 * JSON Schema estricto. El modelo solo describe lo que ve; no recibe los datos de la orden ni de las cuentas
 * de la empresa. La comparación la hace ReceiptChecker.
 */
class ReceiptReader
{
    public const KINDS = ['pago_movil', 'transferencia', 'binance', 'zinli', 'zelle', 'paypal', 'efectivo', 'otro', 'ilegible'];

    private const MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const INSTRUCTIONS = <<<'TXT'
Eres un lector de comprobantes de pago de una tienda en Venezuela. Recibes UNA imagen que una vendedora o una agencia de reparto subió como comprobante del pago de un pedido. Describe solo lo que se ve en la imagen. No adivines: si un dato no se ve o no se lee con seguridad, devuélvelo como null.

Clasifica la imagen en "tipo":
- pago_movil: capture de un Pago Móvil (P2P) en bolívares desde la app o la web de un banco venezolano (Banesco, Mercantil, Banco de Venezuela, Provincial, BNC, Bancamiga, Bicentenario, Tesoro, Venezolano de Crédito, Bancaribe, Exterior, Plaza, 100% Banco, Banplus, Sofitasa, Activo, Mi Banco, etc.). Suele mostrar teléfono e identificación (cédula o RIF) del receptor y un número de referencia.
- transferencia: transferencia bancaria en bolívares a un número de cuenta (20 dígitos), no a un teléfono.
- binance: pago o envío por Binance (Binance Pay, USDT).
- zinli, zelle, paypal: capture de esas plataformas.
- efectivo: foto de billetes o monedas físicas (dólares, bolívares o euros).
- otro: cualquier otra cosa (una foto del producto, una conversación, un documento que no es un pago).
- ilegible: es un comprobante o una foto de dinero, pero no se puede leer lo importante (borrosa, cortada, muy oscura, reflejo). Explica por qué en "motivo_ilegible".

Reglas de formato:
- Teléfonos, cédulas, RIF, números de cuenta, correos y referencias van como texto, tal como se ven, conservando los ceros iniciales. Si el banco oculta dígitos o letras (asteriscos, puntos, x), ponlos como "*" en la misma posición.
- "receptor_*" es el BENEFICIARIO o destino del pago, nunca quien paga. "banco_destino" es el banco del beneficiario; "banco_origen" el de quien paga. Si el capture solo muestra un banco y no se sabe si es origen o destino, ponlo en banco_origen.
- "monto" es un número con punto decimal. En Venezuela se escribe "Bs. 12.345,67" o "12.345,67 Bs": eso es 12345.67. "moneda": VES para bolívares (Bs, Bs.S, Bs.D, VES), USD, USDT o EUR.
- "fecha" en formato AAAA-MM-DD (en Venezuela se escribe DD/MM/AAAA) y "hora" en 24 horas HH:MM.
- "estado": exitosa si dice exitosa, aprobada, completada, enviada o similar; pendiente o rechazada si lo dice; no_visible si no se indica.
- Si es efectivo: "efectivo_moneda", las denominaciones que se ven con seguridad (valor de cada billete y cuántos hay) y "efectivo_total_visible" solo si se pueden contar todos los billetes con seguridad. Si no es efectivo, deja la lista vacía y esos campos en null.
- "confianza": alta si se leen bien los datos principales, media si algunos cuestan, baja si casi nada.
TXT;

    public function read(PaymentReceipt $receipt): array
    {
        $result = $this->call($receipt, self::INSTRUCTIONS, 'Lee este comprobante.', 'comprobante_de_pago', self::schema(), 'high');
        if (!in_array($result['data']['tipo'] ?? null, self::KINDS, true)) {
            throw new RuntimeException('Respuesta sin el formato esperado (tipo ' . json_encode($result['data']['tipo'] ?? null) . ').');
        }

        return $result;
    }

    private const RECHECK_INSTRUCTIONS = <<<'TXT'
Eres un lector muy cuidadoso de comprobantes de pago de Venezuela. Lee SOLO estos datos de la imagen y cópialos carácter por carácter, exactamente como se ven:
- monto_tal_cual: el monto del pago tal como aparece, con sus puntos, comas y moneda (por ejemplo "Bs. 12.345,67" o "8.800,50 Bs"). Es el monto que se pagó, no una comisión ni el saldo de la cuenta.
- monto_digitos: los dígitos de la parte entera de ese monto, uno por uno y separados por espacios, sin puntos, comas ni decimales (para "12.345,67" es "1 2 3 4 5"; para "8.800,50" es "8 8 0 0").
- telefono_destino, cedula_destino y cuenta_destino: el teléfono, la cédula o RIF y el número de cuenta del BENEFICIARIO o destino del pago (nunca de quien paga), escritos dígito por dígito y separados por espacios (por ejemplo "0 4 1 4 5 5 5 1 2 3 4"), con la letra de la cédula si se ve ("V 1 2 3 4 5 6 7 8"). Si el banco tapa dígitos con asteriscos, puntos o x, pon "*" en su lugar.
Cuando hay dígitos repetidos seguidos (como 55, 444 o 000) cuéntalos uno por uno: no los juntes ni los saltes. Si un dato no se ve, null.
TXT;
    // Los ejemplos son números inventados a propósito: con un monto real (33.705) o el teléfono de la empresa
    // como ejemplo, la IA podría copiarlo cuando no ve bien y dar por bueno lo que no es.

    /**
     * Segunda lectura, solo de lo que no cuadró (2026-10-04): la IA a veces junta los dígitos repetidos (leyó
     * "3.705" donde decía 33.705 y 0412-244948 por un teléfono con tres "4" seguidos). Leyendo dígito por
     * dígito y con la imagen a resolución completa se corrige (probado con los comprobantes reales de la
     * semana). Como la primera, no recibe los datos esperados.
     */
    public function reread(PaymentReceipt $receipt): array
    {
        $result = $this->call($receipt, self::RECHECK_INSTRUCTIONS, 'Lee estos datos.', 'relectura', self::rereadSchema(), 'original');
        $d = $result['data'];

        return [
            'monto' => self::parseAmount($d['monto_tal_cual'], $d['monto_digitos']),
            'monto_tal_cual' => $d['monto_tal_cual'],
            'monto_digitos' => $d['monto_digitos'],
            'telefono' => $d['telefono_destino'],
            'cedula' => $d['cedula_destino'],
            'cuenta' => $d['cuenta_destino'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ];
    }

    /**
     * "33.705,00 Bs" → 33705.00. La parte entera sale de los dígitos uno por uno si la IA los dio, y los
     * decimales, del monto tal cual.
     */
    public static function parseAmount(?string $asShown, ?string $digits): ?float
    {
        $int = $digits !== null ? preg_replace('/\D/', '', $digits) : '';
        $intShown = '';
        $decimals = '00';
        if ($asShown !== null && preg_match('/\d[\d.,\s]*/', $asShown, $m)) {
            $num = rtrim(preg_replace('/\s+/', '', $m[0]), '.,');
            if (preg_match('/^(.*)[.,](\d{1,2})$/', $num, $p)) {
                // con decimales: 33.705,00 o 33705.00
                [$intShown, $decimals] = [preg_replace('/\D/', '', $p[1]), str_pad($p[2], 2, '0')];
            } else {
                // sin decimales: 33.705 (el punto separa los miles)
                $intShown = preg_replace('/\D/', '', $num);
            }
        }
        // Si la IA puso también los decimales en los dígitos ("3 3 7 0 5 0 0" por 33.705,00), se quitan
        if ($intShown !== '' && strlen($int) - strlen($decimals) >= strlen($intShown) && str_ends_with($int, $decimals)) {
            $int = substr($int, 0, -strlen($decimals));
        }
        $int = $int !== '' ? $int : $intShown;

        return $int === '' ? null : (float) ($int . '.' . $decimals);
    }

    /**
     * Manda la imagen con las instrucciones y el JSON Schema, y devuelve lo leído y lo que se gastó.
     * $detail: "high" (la imagen reducida) u "original" (completa: más tokens, lee mejor los dígitos chicos).
     */
    private function call(PaymentReceipt $receipt, string $instructions, string $prompt, string $name, array $schema, string $detail): array
    {
        $key = config('services.openai.key');
        if (!$key) {
            throw new RuntimeException('Falta OPENAI_API_KEY en el .env.');
        }

        $disk = Storage::disk('public');
        if (!$receipt->path || !$disk->exists($receipt->path)) {
            throw new RuntimeException('No se encontró la imagen del comprobante.');
        }
        $mime = $disk->mimeType($receipt->path) ?: 'image/jpeg';
        if (!in_array($mime, self::MIME, true)) {
            throw new RuntimeException("Formato de imagen no soportado ({$mime}).");
        }
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($disk->get($receipt->path));
        $model = config('services.openai.receipt_model', 'gpt-6-luna');

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(60)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false, // las fotos traen datos bancarios de los clientes
                'reasoning' => ['effort' => 'none'],
                'instructions' => $instructions,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                        ['type' => 'input_image', 'image_url' => $dataUrl, 'detail' => $detail],
                    ],
                ]],
                'text' => ['format' => [
                    'type' => 'json_schema',
                    'name' => $name,
                    'strict' => true,
                    'schema' => $schema,
                ]],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) $message, 0, 300));
        }

        $json = $response->json();
        $text = null;
        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('El modelo no quiso leer la imagen: ' . ($content['refusal'] ?? ''));
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || array_diff($schema['required'], array_keys($data))) {
            throw new RuntimeException('Respuesta sin el formato esperado (estado ' . ($json['status'] ?? '?') . ').');
        }

        return [
            'data' => $data,
            'model' => $json['model'] ?? $model,
            'response_id' => $json['id'] ?? null,
            'input_tokens' => $json['usage']['input_tokens'] ?? null,
            'output_tokens' => $json['usage']['output_tokens'] ?? null,
        ];
    }

    /** JSON Schema estricto de la segunda lectura. */
    public static function rereadSchema(): array
    {
        $str = ['type' => ['string', 'null']];
        $fields = ['monto_tal_cual', 'monto_digitos', 'telefono_destino', 'cedula_destino', 'cuenta_destino'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array_fill_keys($fields, $str),
            'required' => $fields,
        ];
    }

    /** JSON Schema estricto: todos los campos obligatorios, los que pueden faltar admiten null. */
    public static function schema(): array
    {
        $str = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'tipo' => ['type' => 'string', 'enum' => self::KINDS],
                'motivo_ilegible' => $str,
                'banco_origen' => $str,
                'banco_destino' => $str,
                'monto' => ['type' => ['number', 'null']],
                'moneda' => ['type' => ['string', 'null'], 'enum' => ['VES', 'USD', 'USDT', 'EUR', null]],
                'receptor_nombre' => $str,
                'receptor_telefono' => $str,
                'receptor_identificacion' => $str,
                'receptor_cuenta' => $str,
                'receptor_correo' => $str,
                'referencia' => $str,
                'fecha' => $str,
                'hora' => $str,
                'estado' => ['type' => 'string', 'enum' => ['exitosa', 'pendiente', 'rechazada', 'no_visible']],
                'efectivo_moneda' => ['type' => ['string', 'null'], 'enum' => ['USD', 'VES', 'EUR', null]],
                'efectivo_denominaciones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'valor' => ['type' => 'number'],
                            'cantidad' => ['type' => 'integer'],
                        ],
                        'required' => ['valor', 'cantidad'],
                    ],
                ],
                'efectivo_total_visible' => ['type' => ['number', 'null']],
                'confianza' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']],
            ],
            'required' => [
                'tipo', 'motivo_ilegible', 'banco_origen', 'banco_destino', 'monto', 'moneda', 'receptor_nombre',
                'receptor_telefono', 'receptor_identificacion', 'receptor_cuenta', 'receptor_correo', 'referencia',
                'fecha', 'hora', 'estado', 'efectivo_moneda', 'efectivo_denominaciones', 'efectivo_total_visible', 'confianza',
            ],
        ];
    }
}
