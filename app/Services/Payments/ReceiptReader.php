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
                'instructions' => self::INSTRUCTIONS,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'Lee este comprobante.'],
                        ['type' => 'input_image', 'image_url' => $dataUrl, 'detail' => 'high'],
                    ],
                ]],
                'text' => ['format' => [
                    'type' => 'json_schema',
                    'name' => 'comprobante_de_pago',
                    'strict' => true,
                    'schema' => self::schema(),
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
        if (!is_array($data) || !in_array($data['tipo'] ?? null, self::KINDS, true)) {
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
