<?php

namespace App\Services\Delivery\Closures;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La IA de los cierres (Módulo 3 de Fran, respuesta a la pregunta 1 del 2026-10-09: "que el sistema con inteligencia
 * artificial pueda detectar esas coincidencias… porque a esa nueva agencia no le vamos a poder pedir un formato exacto").
 * Hace tres cosas, y ninguna decide un monto (§48: "IA decidiendo discrepancias" queda fuera):
 *  - dice qué columna del Excel es cada dato (§24), y las filas se leen después con código;
 *  - saca las filas de un PDF, copiando el texto tal cual, y el código comprueba que cada monto está en esa línea;
 *  - propone con qué carrera va una fila que no se pudo emparejar por número de orden ni por teléfono (§27). El código
 *    comprueba la propuesta y, si no hay seguridad, la deja como "posible" para el Admin.
 * Como los comprobantes: la Responses API con un JSON Schema estricto y sin guardar nada en OpenAI (store: false).
 */
class ClosureAi
{
    public function available(): bool
    {
        return (bool) config('services.openai.key');
    }

    private const COLUMNS_INSTRUCTIONS = <<<'TXT'
Recibes las primeras filas de una hoja de cálculo que una agencia de reparto de Venezuela envía cada semana para cobrar sus carreras (entregas) a una tienda. Cada fila de datos es una carrera. Las columnas van con su letra (A, B, C…) y las filas con su número.
Di cuál es la fila de títulos (o 0 si no hay títulos) y qué columna tiene cada dato:
- orden: el número o código del pedido de la tienda (por ejemplo "#4617", "##54563", "MAN-27114"). No es el número de fila ni un ID interno de la agencia.
- cliente: el nombre del cliente que recibe.
- telefono: el teléfono del cliente.
- fecha: la fecha de la carrera.
- monto: lo que la agencia cobra por esa carrera (costo, tarifa, total, precio). No es el precio del producto ni el monto que pagó el cliente.
- zona: la dirección, zona, sector o ubicación de la entrega.
Si un dato no está, null. No inventes columnas: usa solo las letras que aparecen.
TXT;

    /**
     * Qué columna es cada dato, a partir de una muestra de la hoja ("Fila 1: A=orden | B=cliente | …").
     * @return array{header_row: int, columns: array<string, ?string>}
     */
    public function columns(string $sample): array
    {
        $col = ['type' => ['string', 'null']];
        $fields = ['orden', 'cliente', 'telefono', 'fecha', 'monto', 'zona'];
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'fila_titulos' => ['type' => 'integer'],
                'columnas' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => array_fill_keys($fields, $col),
                    'required' => $fields,
                ],
            ],
            'required' => ['fila_titulos', 'columnas'],
        ];
        $data = $this->call(self::COLUMNS_INSTRUCTIONS, $sample, 'columnas_cierre', $schema)['data'];

        return ['header_row' => (int) $data['fila_titulos'], 'columns' => $data['columnas']];
    }

    private const PDF_INSTRUCTIONS = <<<'TXT'
Recibes el texto de un PDF que una agencia de reparto de Venezuela envía cada semana para cobrar sus carreras (entregas) a una tienda. Cada línea va con su número ("L12: …"). Una carrera puede ocupar una o dos líneas (a veces el monto queda en la línea siguiente).
Devuelve TODAS las carreras, en el orden en que aparecen, sin saltar ninguna y sin repetir. No incluyas títulos, subtotales, totales, encabezados de página ni datos de la factura.
Para cada carrera copia el texto EXACTAMENTE como está en el documento, sin corregirlo:
- lineas: los números de las líneas de donde sale la carrera.
- monto_texto: lo que la agencia cobra por esa carrera (tarifa, costo, total), tal cual ("3,00", "1,5", "7,5"). No confundas el monto con la distancia, la hora ni un número de teléfono. Si la carrera no tiene monto, null.
- fecha_texto: la fecha de la carrera tal cual ("28-sept-2026", "1/10/26"). Si no tiene, null.
- cliente: el nombre del cliente tal cual. Si en el lugar del nombre hay otra cosa (por ejemplo "efectivo"), cópiala igual.
- telefono: el teléfono del cliente si aparece en la carrera, tal cual; si no, null.
- orden: el número de pedido de la tienda si aparece; si no, null.
- zona: la dirección, zona o ubicación si aparece; si no, null.
Además, "total_documento_texto": el total general que dice el documento, tal cual, o null si no lo dice.
TXT;

    /**
     * Las carreras de un PDF ya pasado a texto con sus líneas numeradas.
     * @return array{rows: array<int, array>, total_text: ?string, usage: array}
     */
    public function pdfRows(string $numberedText): array
    {
        $str = ['type' => ['string', 'null']];
        $rowFields = ['lineas', 'monto_texto', 'fecha_texto', 'cliente', 'telefono', 'orden', 'zona'];
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'carreras' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'lineas' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'monto_texto' => $str,
                            'fecha_texto' => $str,
                            'cliente' => $str,
                            'telefono' => $str,
                            'orden' => $str,
                            'zona' => $str,
                        ],
                        'required' => $rowFields,
                    ],
                ],
                'total_documento_texto' => $str,
            ],
            'required' => ['carreras', 'total_documento_texto'],
        ];
        $result = $this->call(self::PDF_INSTRUCTIONS, $numberedText, 'carreras_pdf', $schema, 'low');

        return ['rows' => $result['data']['carreras'], 'total_text' => $result['data']['total_documento_texto'], 'usage' => $result['usage']];
    }

    private const MATCH_INSTRUCTIONS = <<<'TXT'
Ayudas a revisar el cierre semanal de una agencia de reparto de Venezuela. Recibes:
1) FILAS: las carreras que cobra la agencia y que no se pudieron emparejar por número de pedido ni por teléfono. Cada una con su número de fila, fecha, nombre del cliente tal como lo escribió la agencia (a veces solo el primer nombre, con errores de ortografía o sin tildes), teléfono y zona si los hay.
2) CARRERAS: las carreras que el sistema de la tienda registró para esa agencia esa semana, cada una con su id, fecha, número de pedido, nombre y teléfono del cliente y su dirección.
Para cada fila, di qué carrera es, si la hay. Usa el nombre, la fecha (normalmente el mismo día; a veces un día de diferencia), el teléfono y la zona contra la dirección. Una carrera solo puede ir con una fila.
- confianza "alta": el nombre coincide (aunque esté abreviado o mal escrito), la fecha coincide o difiere un día, y no hay otra carrera que también encaje.
- "media": encaja pero hay dudas (otra carrera parecida, solo coincide el primer nombre y es común, la fecha difiere más).
- "baja": casi no hay datos.
Si no encuentras ninguna, carrera_id null. Nunca inventes un id. En "motivo", una frase corta en español con lo que coincide o lo que hace dudar.
TXT;

    /**
     * Propuestas de carrera para las filas que quedaron sin emparejar. La decisión final la toma el código (§27).
     * @return array<int, array{fila: int, carrera_id: ?int, confianza: string, motivo: string}>
     */
    public function suggestMatches(string $rowsText, string $tripsText): array
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'propuestas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'fila' => ['type' => 'integer'],
                            'carrera_id' => ['type' => ['integer', 'null']],
                            'confianza' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']],
                            'motivo' => ['type' => 'string'],
                        ],
                        'required' => ['fila', 'carrera_id', 'confianza', 'motivo'],
                    ],
                ],
            ],
            'required' => ['propuestas'],
        ];
        $input = "FILAS:\n{$rowsText}\n\nCARRERAS:\n{$tripsText}";

        return $this->call(self::MATCH_INSTRUCTIONS, $input, 'coincidencias_cierre', $schema, 'low')['data']['propuestas'];
    }

    private function call(string $instructions, string $input, string $name, array $schema, string $effort = 'none'): array
    {
        $key = config('services.openai.key');
        if (!$key) {
            throw new RuntimeException('Falta OPENAI_API_KEY en el .env.');
        }
        $model = config('services.openai.closure_model', 'gpt-6-luna');

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(180)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false, // nombres, teléfonos y direcciones de clientes
                'reasoning' => ['effort' => $effort],
                'instructions' => $instructions,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $input]]]],
                'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'strict' => true, 'schema' => $schema]],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new RuntimeException('OpenAI respondió ' . $response->status() . ': ' . mb_strimwidth((string) $message, 0, 300));
        }
        $json = $response->json();
        $text = null;
        foreach ($json['output'] ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('La IA no quiso leer el archivo: ' . ($content['refusal'] ?? ''));
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = $content['text'];
                }
            }
        }
        $data = $text !== null ? json_decode($text, true) : null;
        if (!is_array($data) || array_diff($schema['required'], array_keys($data))) {
            throw new RuntimeException('La IA respondió sin el formato esperado (estado ' . ($json['status'] ?? '?') . ').');
        }

        return [
            'data' => $data,
            'usage' => ['model' => $json['model'] ?? $model, 'input_tokens' => $json['usage']['input_tokens'] ?? null, 'output_tokens' => $json['usage']['output_tokens'] ?? null],
        ];
    }
}
