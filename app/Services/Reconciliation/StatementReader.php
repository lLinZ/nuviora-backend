<?php

namespace App\Services\Reconciliation;

use App\Models\BankStatementRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * Lee un extracto y lo lleva a una estructura estándar (documento de Fran del 2026-10-06, §17): fecha, referencia,
 * descripción y monto de cada ingreso, con la fila del archivo de la que salió.
 * - Cada banco entrega su propio formato. Las columnas se reconocen por su nombre (Fecha, Referencia, Crédito…) y la
 *   primera vez el administrador confirma cuál es cuál; eso queda guardado para ese extracto.
 * - Los valores salen siempre de las celdas del archivo, con reglas fijas: nada se adivina ni se inventa.
 * - Solo se devuelven los ingresos. Los egresos no se guardan (§23: no se analizan los movimientos personales).
 */
class StatementReader
{
    /** Excel, OpenDocument, CSV, TXT con separadores y HTML (algunos bancos lo bajan como .xls). */
    public const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv', 'txt', 'html', 'htm'];

    /** Los datos de cada movimiento y cómo se reconoce su columna por el título (sin acentos y en minúsculas). */
    public const FIELDS = [
        'date' => '/^(fecha|date)\b|fecha (de |del )?(la )?(operacion|transaccion|valor|movimiento)/',
        'reference' => '/referencia|^ref\b|^(nro|num|numero|no)\.? *(de )?(ref|transac|operac|document|comprobante)|comprobante|^documento|transaction id|order id|^txid/',
        'credit' => '/credito|abono|ingreso|haber|deposito|^credit/',
        'debit' => '/debito|cargo|egreso|^debe$|retiro|^debit/',
        'type' => '/^(tipo|naturaleza|c\/d|cr\/db|signo)$/',
        'amount' => '/^monto|importe|^valor|^amount/',
        'description' => '/descripcion|concepto|detalle|^movimiento|^description|^nota/',
    ];

    /** Qué dice la columna "tipo" en un ingreso. */
    private const CREDIT_TYPE = '/^(c|cr|cred|credito|abono|ingreso|haber|\+)$/';

    /**
     * Las celdas de la primera hoja, fila por fila (la clave es el número de fila del archivo, desde 1). Cada celda
     * trae el texto que se ve y, si es un número, también el número.
     * @return array<int, array<int, array{text: string, number: ?float}>>
     */
    public function cells(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Formato de archivo no soportado: sube el extracto en Excel (.xlsx o .xls), CSV o TXT.');
        }
        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->delimited((string) file_get_contents($path));
        }
        try {
            $sheet = IOFactory::load($path)->getSheet(0);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo abrir el archivo como hoja de cálculo.');
        }
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getCalculatedValue();
                $text = trim((string) $cell->getFormattedValue());
                if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                    $text = ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d H:i');
                    $value = null;
                }
                $cells[] = ['text' => $text, 'number' => is_int($value) || is_float($value) ? (float) $value : null];
            }
            $rows[$row->getRowIndex()] = $cells;
        }

        return $rows;
    }

    /**
     * Busca la fila de títulos y qué columna es cada dato. Null si no hay una fila con fecha y monto.
     * @return array{header_row: int, columns: array<string, int>, mode: string, headers: array<int, string>, signature: string}|null
     */
    public function detect(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 40, true) as $line => $cells) {
            $headers = array_map(fn ($c) => $c['text'], $cells);
            $columns = [];
            foreach ($headers as $index => $header) {
                $norm = $this->norm($header);
                if ($norm === '' || str_contains($norm, 'saldo')) {
                    continue;
                }
                foreach (self::FIELDS as $field => $pattern) {
                    if (!isset($columns[$field]) && preg_match($pattern, $norm)) {
                        $columns[$field] = $index;
                        break;
                    }
                }
            }
            if (!isset($columns['date']) || !(isset($columns['credit']) || isset($columns['amount']))) {
                continue;
            }
            $mode = isset($columns['credit']) ? 'credit' : (isset($columns['type']) ? 'type' : 'signed');

            return ['header_row' => $line, 'columns' => $columns, 'mode' => $mode, 'headers' => $headers, 'signature' => $this->signature($headers)];
        }

        return null;
    }

    /** Los títulos de las columnas tal como están en esa fila (para comparar con un formato guardado). */
    public function signatureAt(array $rows, int $headerRow): ?string
    {
        return isset($rows[$headerRow]) ? $this->signature(array_map(fn ($c) => $c['text'], $rows[$headerRow])) : null;
    }

    /**
     * Los movimientos del archivo con el formato dado: los ingresos normalizados, y cuántos movimientos tenía en total.
     * @return array{credits: array<int, array>, read: int, debits: int}
     */
    public function credits(array $rows, array $format): array
    {
        $col = $format['columns'];
        $dates = [];
        foreach ($rows as $line => $cells) {
            if ($line > $format['header_row'] && isset($col['date'])) {
                $dates[] = $cells[$col['date']]['text'] ?? '';
            }
        }
        $dayFirst = $this->dayFirst($dates);

        $credits = [];
        $read = 0;
        $debits = 0;
        foreach ($rows as $line => $cells) {
            if ($line <= $format['header_row']) {
                continue;
            }
            $date = $this->date($cells[$col['date']] ?? null, $dayFirst);
            $amount = match ($format['mode']) {
                'credit' => $this->amount($cells[$col['credit']] ?? null),
                default => $this->amount($cells[$col['amount']] ?? null),
            };
            $debit = isset($col['debit']) ? $this->amount($cells[$col['debit']] ?? null) : null;
            if ($date === null || ($amount === null && $debit === null)) {
                continue; // títulos repetidos, saldos, totales o filas vacías
            }
            $read++;
            $isCredit = match ($format['mode']) {
                'credit' => $amount !== null && $amount > 0,
                'type' => $amount !== null && $amount != 0 && preg_match(self::CREDIT_TYPE, $this->norm($cells[$col['type']]['text'] ?? '')),
                default => $amount !== null && $amount > 0,
            };
            if (!$isCredit) {
                $debits++;
                continue;
            }
            $reference = isset($col['reference']) ? $this->reference($cells[$col['reference']] ?? null) : null;
            $credits[] = [
                'line' => $line,
                'date' => $date,
                'reference' => $reference,
                'reference_norm' => BankStatementRow::normalizeReference($reference),
                'description' => isset($col['description']) ? (mb_substr($cells[$col['description']]['text'] ?? '', 0, 255) ?: null) : null,
                'amount' => round(abs($amount), 2),
                'raw' => array_map(fn ($c) => $c['text'], $cells),
            ];
        }

        return ['credits' => $credits, 'read' => $read, 'debits' => $debits];
    }

    // --- Lectura de valores ---

    /** CSV o TXT con separadores: ; , tabulador o |. Si no está en UTF-8, se pasa desde Windows-1252. */
    private function delimited(string $content): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $sample = implode("\n", array_slice($lines, 0, 30));
        $delimiter = collect([';', "\t", ',', '|'])->sortByDesc(fn ($d) => substr_count($sample, $d))->first();
        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[$i + 1] = array_map(fn ($v) => ['text' => trim((string) $v), 'number' => null], str_getcsv($line, $delimiter, '"', '\\'));
        }

        return $rows;
    }

    /** "1.234,56", "1,234.56", "-1.234,56", "1.234,56-", "(1.234,56)", "Bs. 1.234,56" → número. Null si no hay número. */
    public function amount(?array $cell): ?float
    {
        if ($cell === null) {
            return null;
        }
        if ($cell['number'] !== null) {
            return $cell['number'];
        }
        $text = trim($cell['text']);
        if ($text === '' || !preg_match('/\d/', $text)) {
            return null;
        }
        $negative = str_starts_with($text, '-') || str_ends_with($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')'));
        $num = preg_replace('/[^\d.,]/', '', $text);
        $lastDot = strrpos($num, '.');
        $lastComma = strrpos($num, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false || $lastComma !== false) {
            $sep = $lastDot !== false ? '.' : ',';
            $parts = explode($sep, $num);
            // Un solo separador seguido de 1 o 2 dígitos es el decimal; con 3 dígitos separa los miles (1.234)
            $decimal = count($parts) === 2 && strlen(end($parts)) <= 2 ? $sep : null;
        } else {
            $decimal = null;
        }
        $thousands = $decimal === '.' ? ',' : '.';
        $num = str_replace($thousands, '', $num);
        if ($decimal === ',') {
            $num = str_replace(',', '.', $num);
        } elseif ($decimal === null) {
            $num = str_replace([',', '.'], '', $num);
        }
        if (!is_numeric($num)) {
            return null;
        }

        return $negative ? -(float) $num : (float) $num;
    }

    /** Fecha del movimiento: 05/10/2026, 05-10-2026, 2026-10-05, con hora o sin ella. */
    public function date(?array $cell, bool $dayFirst = true): ?string
    {
        if ($cell === null) {
            return null;
        }
        $text = trim($cell['text']);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $text, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $text, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$d, $mo] = $dayFirst ? [$a, $b] : [$b, $a];
            $y = $y < 100 ? 2000 + $y : $y;
        } elseif ($cell['number'] !== null && $cell['number'] > 20000 && $cell['number'] < 80000) {
            return ExcelDate::excelToDateTimeObject($cell['number'])->format('Y-m-d');
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Si las fechas vienen como día/mes (lo normal en Venezuela) o mes/día: lo dice cualquier fecha con un número > 12. */
    private function dayFirst(array $dates): bool
    {
        foreach ($dates as $text) {
            if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.]\d{2,4}/', trim((string) $text), $m)) {
                if ((int) $m[1] > 12) {
                    return true;
                }
                if ((int) $m[2] > 12) {
                    return false;
                }
            }
        }

        return true;
    }

    /** La referencia tal como está. Un número de Excel se escribe entero (sin "1,23E+11"). */
    private function reference(?array $cell): ?string
    {
        if ($cell === null) {
            return null;
        }
        if ($cell['number'] !== null && $cell['text'] !== '' && !preg_match('/^[\d\s]+$/', $cell['text'])) {
            return number_format($cell['number'], 0, '', '');
        }
        $text = trim($cell['text']);

        return $text === '' ? null : mb_substr($text, 0, 64);
    }

    private function norm(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))), " .:");
    }

    private function signature(array $headers): string
    {
        return implode('|', array_filter(array_map(fn ($h) => $this->norm($h), $headers), fn ($h) => $h !== ''));
    }
}
