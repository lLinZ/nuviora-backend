<?php

namespace App\Services\Delivery\Closures;

use App\Models\AgencyClosureSetting;
use App\Services\Reconciliation\StatementReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Lee el archivo de cierre de una agencia y devuelve sus carreras (Módulo 3 de Fran, §24 y §25): número de orden,
 * cliente, teléfono, fecha, monto cobrado y zona, más la fila tal cual venía (§26).
 *  - Excel, CSV u ODS: cada agencia tiene su mapeo de columnas (§24). Si no lo tiene, o su archivo cambió de forma, se
 *    reconoce por los títulos y, si no alcanza, lo dice la IA. El mapeo queda guardado y el Admin lo puede corregir.
 *    Los valores salen siempre de las celdas, con código.
 *  - PDF (la respuesta de Fran a la pregunta 1): la IA separa las carreras del texto del PDF, y cada monto se comprueba
 *    contra la línea de donde dice que salió. Si no está ahí, la fila queda sin monto y va a revisión.
 */
class ClosureFileReader
{
    public const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv', 'pdf'];
    public const FIELDS = ['orden', 'cliente', 'telefono', 'fecha', 'monto', 'zona'];

    /** Cómo se reconoce cada columna por su título (sin tildes y en minúsculas). */
    private const HEADERS = [
        'orden' => '/^(orden|pedido|order|n(ro|um|o)?\.? *(de )?(orden|pedido)|id( orden| pedido)?|#|guia|factura)$/',
        'cliente' => '/cliente|destinatario|nombre|receptor|customer/',
        'telefono' => '/telefono|tlf|telf|celular|movil|phone|whatsapp/',
        'fecha' => '/^(fecha|date|dia)\b/',
        'monto' => '/^(costo|monto|total|tarifa|precio|importe|valor|cobro|delivery|carrera|flete)\b/',
        'zona' => '/zona|ubicacion|direccion|sector|destino|urbanizacion|municipio/',
    ];

    public function __construct(private StatementReader $sheet, private ClosureAi $ai)
    {
    }

    /**
     * @return array{rows: array<int, array>, extraction: array, mapping: ?array}
     *   rows: position, raw, orden, cliente, telefono, fecha (Y-m-d), fecha_texto, monto, monto_texto, zona, nota
     *   mapping: el mapeo de columnas que se usó (para guardarlo en la agencia), o null si fue un PDF
     */
    public function read(string $path, string $extension, AgencyClosureSetting $setting): array
    {
        $extension = strtolower($extension);
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Formato no soportado: sube el cierre en Excel (.xlsx o .xls), CSV o PDF.');
        }

        return $extension === 'pdf' ? $this->pdf($path) : $this->spreadsheet($path, $extension, $setting);
    }

    // --- Excel ---

    private function spreadsheet(string $path, string $extension, AgencyClosureSetting $setting): array
    {
        $cells = $this->sheet->cells($path, $extension);
        if (!$cells) {
            throw new RuntimeException('La hoja está vacía.');
        }
        $saved = $setting->file_mapping;
        $mapping = null;
        $source = null;
        if ($saved && isset($saved['columns'], $saved['header_row']) && $this->fits($cells, $saved)) {
            [$mapping, $source] = [$saved, $saved['source'] ?? 'guardado'];
        }
        if (!$mapping && ($found = $this->detect($cells))) {
            [$mapping, $source] = [$found, 'titulos'];
        }
        $usage = null;
        if (!$mapping && $this->ai->available()) {
            $answer = $this->ai->columns($this->sample($cells));
            $columns = array_filter(array_map(fn ($c) => is_string($c) && preg_match('/^[A-Z]{1,2}$/', $c) ? $c : null, $answer['columns']));
            if (isset($columns['monto']) && (isset($columns['orden']) || isset($columns['cliente']))) {
                $mapping = ['header_row' => $answer['header_row'], 'columns' => $columns];
                $source = 'ia';
            }
        }
        if (!$mapping) {
            throw new RuntimeException('No se reconocieron las columnas del archivo (hace falta al menos el monto, y el número de orden o el cliente). Configura el mapeo de esta agencia y vuelve a procesar.');
        }
        $mapping['signature'] = $this->signatureAt($cells, (int) $mapping['header_row']);
        $mapping['source'] = $source;

        $rows = [];
        $col = fn (string $field) => isset($mapping['columns'][$field]) ? Coordinate::columnIndexFromString($mapping['columns'][$field]) - 1 : null;
        $index = array_combine(self::FIELDS, array_map($col, self::FIELDS));
        foreach ($cells as $line => $row) {
            if ($line <= (int) $mapping['header_row']) {
                continue;
            }
            $value = fn (string $f) => $index[$f] !== null ? ($row[$index[$f]] ?? null) : null;
            $text = fn (string $f) => trim((string) ($value($f)['text'] ?? '')) ?: null;
            $amountCell = $value('monto');
            $amount = $amountCell ? $this->sheet->amount($amountCell) : null;
            $order = $text('orden');
            $client = $text('cliente');
            $dateCell = $value('fecha');
            $date = $dateCell ? ($this->sheet->date($dateCell) ?? ClosureNormalizer::date($dateCell['text'])) : null;
            if ($order === null && $client === null && $date === null) {
                continue; // filas vacías, totales y notas al pie
            }
            if ($order === null && $client === null && preg_match('/total|subtotal|suma/i', implode(' ', array_column($row, 'text')))) {
                continue;
            }
            $raw = [];
            foreach ($row as $i => $c) {
                if ($c['text'] !== '') {
                    $raw[Coordinate::stringFromColumnIndex($i + 1)] = $c['text'];
                }
            }
            $rows[] = [
                'position' => $line,
                'raw' => ['fila' => $line, 'celdas' => $raw],
                'orden' => $order,
                'cliente' => $client,
                'telefono' => $text('telefono'),
                'fecha' => $date,
                'fecha_texto' => $text('fecha'),
                'monto' => $amount !== null ? round($amount, 2) : null,
                'monto_texto' => $text('monto'),
                'zona' => $text('zona'),
                'nota' => null,
            ];
        }

        return [
            'rows' => $rows,
            'mapping' => $mapping,
            'extraction' => ['method' => 'excel', 'mapping_source' => $source, 'header_row' => $mapping['header_row'], 'columns' => $mapping['columns']],
        ];
    }

    /** Si el mapeo guardado sirve: el que puso el Admin a mano, siempre; el automático, si los títulos de esa fila son los mismos. */
    private function fits(array $cells, array $mapping): bool
    {
        return !empty($mapping['manual']) || (int) $mapping['header_row'] === 0
            ? count($cells) > 0
            : ($mapping['signature'] ?? null) !== null && $this->signatureAt($cells, (int) $mapping['header_row']) === $mapping['signature'];
    }

    /** Busca la fila de títulos con el monto y el número de orden o el cliente. */
    public function detect(array $cells): ?array
    {
        foreach (array_slice($cells, 0, 30, true) as $line => $row) {
            $columns = [];
            foreach ($row as $i => $c) {
                $norm = $this->norm($c['text']);
                if ($norm === '') {
                    continue;
                }
                foreach (self::HEADERS as $field => $pattern) {
                    if (!isset($columns[$field]) && preg_match($pattern, $norm)) {
                        $columns[$field] = Coordinate::stringFromColumnIndex($i + 1);
                        break;
                    }
                }
            }
            if (isset($columns['monto']) && (isset($columns['orden']) || isset($columns['cliente']))) {
                return ['header_row' => $line, 'columns' => $columns];
            }
        }

        return null;
    }

    /** Las primeras filas con la letra de cada columna, para la IA. */
    private function sample(array $cells): string
    {
        $out = [];
        foreach (array_slice($cells, 0, 25, true) as $line => $row) {
            $parts = [];
            foreach ($row as $i => $c) {
                if ($c['text'] !== '') {
                    $parts[] = Coordinate::stringFromColumnIndex($i + 1) . '=' . mb_substr($c['text'], 0, 60);
                }
            }
            if ($parts) {
                $out[] = "Fila {$line}: " . implode(' | ', $parts);
            }
        }

        return implode("\n", $out);
    }

    private function signatureAt(array $cells, int $line): ?string
    {
        if ($line === 0 || !isset($cells[$line])) {
            return null;
        }

        return implode('|', array_filter(array_map(fn ($c) => $this->norm($c['text']), $cells[$line]), fn ($t) => $t !== ''));
    }

    private function norm(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(\Illuminate\Support\Str::ascii($text))), " .:#");
    }

    // --- PDF ---

    /** Las líneas de texto del PDF (sin las vacías), numeradas desde 1. */
    public function pdfLines(string $path): array
    {
        try {
            $text = (new PdfParser())->parseFile($path)->getText();
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo abrir el PDF.');
        }
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            // El tabulador separa columnas en el PDF: se deja marcado para que la IA no junte la zona con el nombre
            $line = trim(preg_replace('/ {2,}/', ' ', str_replace("\t", ' | ', $line)), ' |');
            if ($line !== '') {
                $lines[count($lines) + 1] = $line;
            }
        }

        return $lines;
    }

    private function pdf(string $path): array
    {
        $lines = $this->pdfLines($path);
        if (count($lines) < 2) {
            throw new RuntimeException('El PDF no trae texto (parece una imagen escaneada). Pide a la agencia el Excel o un PDF generado desde su sistema.');
        }
        if (count($lines) > 3000) {
            throw new RuntimeException('El PDF es demasiado largo para leerlo de una vez (' . count($lines) . ' líneas).');
        }
        if (!$this->ai->available()) {
            throw new RuntimeException('Para leer un PDF hace falta la IA (OPENAI_API_KEY en el .env). Mientras tanto, sube el Excel.');
        }
        $numbered = implode("\n", array_map(fn ($n, $l) => "L{$n}: {$l}", array_keys($lines), $lines));
        $answer = $this->ai->pdfRows($numbered);

        $rows = [];
        $unverified = 0;
        foreach ($answer['rows'] as $i => $r) {
            $cited = array_values(array_filter(array_map('intval', $r['lineas'] ?? []), fn ($n) => isset($lines[$n])));
            if (!$cited) {
                continue; // la IA citó líneas que no existen: no se toma
            }
            $source = implode(' ', array_map(fn ($n) => $lines[$n], $cited));
            $amountText = $r['monto_texto'] !== null ? trim($r['monto_texto']) : null;
            $amount = null;
            $note = null;
            if ($amountText !== null && $amountText !== '') {
                if ($this->numberIn($amountText, $source)) {
                    $amount = $this->sheet->amount(['text' => $amountText, 'number' => null]);
                } else {
                    $unverified++;
                    $note = "El monto que leyó la IA ({$amountText}) no está en esa línea del PDF: revísalo.";
                }
            }
            $dateText = $r['fecha_texto'] !== null && str_contains(mb_strtolower($source), mb_strtolower(trim($r['fecha_texto']))) ? trim($r['fecha_texto']) : null;
            $keep = fn (?string $v) => $v !== null && trim($v) !== '' && str_contains(mb_strtolower($source), mb_strtolower(trim($v))) ? trim($v) : null;
            $rows[] = [
                'position' => $cited[0],
                'raw' => ['lineas' => $cited, 'texto' => $source],
                'orden' => $keep($r['orden']),
                'cliente' => $keep($r['cliente']),
                'telefono' => $keep($r['telefono']) ?? ClosureNormalizer::phoneIn($source),
                'fecha' => $dateText ? ClosureNormalizer::date($dateText) : null,
                'fecha_texto' => $dateText,
                'monto' => $amount !== null ? round($amount, 2) : null,
                'monto_texto' => $amountText,
                'zona' => $keep($r['zona']),
                'nota' => $note,
            ];
        }
        usort($rows, fn ($a, $b) => $a['position'] <=> $b['position']);

        // El total del documento, si lo dice, contra la suma de las filas
        $warnings = [];
        $total = $answer['total_text'] !== null ? $this->sheet->amount(['text' => $answer['total_text'], 'number' => null]) : null;
        $sum = round(array_sum(array_column($rows, 'monto')), 2);
        if ($total !== null && abs($total - $sum) > 0.009) {
            $warnings[] = "El documento dice un total de {$answer['total_text']} y la suma de las carreras leídas es {$sum}.";
        }
        if ($unverified) {
            $warnings[] = "{$unverified} montos no se encontraron en su línea del PDF y quedaron para revisar.";
        }

        return [
            'rows' => $rows,
            'mapping' => null,
            'extraction' => ['method' => 'pdf_ia', 'lines' => count($lines), 'total_text' => $answer['total_text'], 'warnings' => $warnings, 'usage' => $answer['usage']],
        ];
    }

    /** Si ese número está escrito en la línea ("3,00" en "… charly sanchez 3,00"), como número suelto. */
    private function numberIn(string $number, string $line): bool
    {
        $n = preg_quote(trim($number), '/');

        return (bool) preg_match('/(?<![\d.,])' . $n . '(?![\d])/u', $line);
    }
}
