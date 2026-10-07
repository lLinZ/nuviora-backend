<?php

namespace App\Services\Reconciliation;

use App\Models\BankStatement;
use App\Models\BankStatementRow;
use App\Models\ReconciliationDay;
use App\Models\ReconciliationEvent;
use App\Models\ReconciliationItem;
use App\Models\StatementSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sube un extracto a una conciliación (documento de Fran del 2026-10-06):
 * - §16: "Después de subirlos, el sistema procesa automáticamente la información": se leen los ingresos y se buscan los
 *   pagos del día.
 * - §17: el formato de cada extracto (qué columna es cada dato) se confirma la primera vez y queda guardado.
 * - §26 y §28: reemplazar un extracto guarda el anterior, con quién lo reemplazó y cuándo, y vuelve a buscar los pagos.
 * - §29: el archivo va al disco privado.
 */
class StatementImporter
{
    public function __construct(
        private StatementReader $reader,
        private StatementMatcher $matcher,
        private DayBuilder $builder,
    ) {
    }

    /**
     * Si el formato del archivo no está confirmado, devuelve lo que se entendió para que el administrador lo confirme
     * (step "confirm"). Si lo está, guarda el extracto, busca los pagos y devuelve step "done".
     * $format: el que confirmó el administrador ({header_row, columns, mode}), o null para usar el guardado.
     */
    public function import(ReconciliationDay $day, StatementSource $source, UploadedFile $file, ?array $format, int $userId, ?BankStatement $replacing = null, bool $preview = false): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $rows = $this->reader->cells($file->getRealPath(), $extension);
        // Ver cómo queda con las columnas que eligió el administrador, sin guardar nada
        if ($preview && $format !== null) {
            $format = $this->validFormat($rows, $format);

            return ['step' => 'confirm'] + $format + $this->preview($this->reader->credits($rows, $format));
        }

        $saved = $source->format;
        if ($format === null && $saved && $this->reader->signatureAt($rows, (int) $saved['header_row']) === ($saved['signature'] ?? null)) {
            $format = $saved;
        }
        if ($format === null) {
            $detected = $this->reader->detect($rows);
            if (!$detected) {
                throw new RuntimeException('No se encontraron las columnas de fecha y monto en el archivo. Sube el extracto como lo descarga el banco, con los títulos de las columnas.');
            }
            $parsed = $this->reader->credits($rows, $detected);

            return ['step' => 'confirm'] + $detected + $this->preview($parsed);
        }

        $format = $this->validFormat($rows, $format);
        $parsed = $this->reader->credits($rows, $format);
        if ($parsed['read'] === 0) {
            throw new RuntimeException('No se encontraron movimientos con fecha y monto en el archivo.');
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $current = $day->statements()->current()->where('statement_source_id', $source->id)->get();
        if ($current->contains('sha256', $sha)) {
            throw new RuntimeException('Ese archivo ya está subido en esta conciliación.');
        }
        if ($current->isNotEmpty() && !$replacing) {
            throw new RuntimeException("Ya hay un extracto de {$source->name} en esta conciliación. Para cambiarlo, usa «Reemplazar extracto».");
        }

        $path = $file->storeAs('statements/' . $day->date->format('Y-m'), Str::uuid() . '.' . $extension, 'local');
        $dates = array_column($parsed['credits'], 'date');

        $statement = DB::transaction(function () use ($day, $source, $file, $format, $parsed, $sha, $path, $dates, $userId, $replacing) {
            if (!$source->format || ($source->format['signature'] ?? null) !== $format['signature']) {
                $source->forceFill(['format' => $format])->save();
            }
            $statement = BankStatement::create([
                'reconciliation_day_id' => $day->id,
                'statement_source_id' => $source->id,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'sha256' => $sha,
                'date_from' => $dates ? min($dates) : null,
                'date_to' => $dates ? max($dates) : null,
                'rows_read' => $parsed['read'],
                'rows_credit' => count($parsed['credits']),
                'uploaded_by' => $userId,
            ]);
            foreach (array_chunk($parsed['credits'], 500) as $chunk) {
                BankStatementRow::insert(array_map(fn ($c) => ['bank_statement_id' => $statement->id, 'raw' => json_encode($c['raw'], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()] + array_diff_key($c, ['raw' => 1]), $chunk));
            }

            $reset = [];
            if ($replacing) {
                $replacing->forceFill(['replaced_at' => now(), 'replaced_by_id' => $statement->id, 'replaced_by_user' => $userId])->save();
                // Lo vinculado a mano a un movimiento del archivo reemplazado se vuelve a buscar (§26). Lo confirmado o
                // marcado como no recibido no depende del archivo y se queda.
                $oldRows = $replacing->rows()->pluck('id');
                $reset = ReconciliationItem::whereIn('bank_statement_row_id', $oldRows)->pluck('id')->all();
                ReconciliationItem::whereIn('id', $reset)->update([
                    'status' => ReconciliationItem::PENDING, 'bank_statement_row_id' => null, 'resolved_by' => null, 'resolved_at' => null,
                ]);
            }
            ReconciliationEvent::log($replacing ? 'replace' : 'upload', $userId, [
                'bank_statement_id' => $statement->id,
                'data' => [
                    'extracto' => $source->name, 'archivo' => $statement->original_name, 'ingresos' => $statement->rows_credit,
                    'movimientos' => $statement->rows_read, 'desde' => $statement->date_from?->toDateString(), 'hasta' => $statement->date_to?->toDateString(),
                ] + ($replacing ? ['reemplaza' => $replacing->id, 'archivo_anterior' => $replacing->original_name, 'pagos_que_se_vuelven_a_buscar' => $reset] : []),
            ]);

            return $statement;
        });

        $this->matcher->matchDay($day);
        $this->builder->refreshStatus($day);

        return ['step' => 'done', 'statement_id' => $statement->id];
    }

    /** El formato que confirmó el administrador: tiene que tener fecha y monto en columnas que existan. */
    private function validFormat(array $rows, array $format): array
    {
        $headerRow = (int) ($format['header_row'] ?? 0);
        $columns = array_filter(array_map(fn ($v) => is_numeric($v) ? (int) $v : null, (array) ($format['columns'] ?? [])), fn ($v) => $v !== null && $v >= 0);
        $columns = array_intersect_key($columns, StatementReader::FIELDS);
        $mode = in_array($format['mode'] ?? null, ['credit', 'signed', 'type'], true) ? $format['mode'] : null;
        $needs = $mode === 'credit' ? 'credit' : 'amount';
        if (!isset($rows[$headerRow]) || !isset($columns['date']) || !$mode || !isset($columns[$needs]) || ($mode === 'type' && !isset($columns['type']))) {
            throw new RuntimeException('Falta indicar la columna de la fecha y la del monto (o la de los ingresos).');
        }
        $headers = array_map(fn ($c) => $c['text'], $rows[$headerRow]);

        return ['header_row' => $headerRow, 'columns' => $columns, 'mode' => $mode, 'headers' => $headers, 'signature' => $this->reader->signatureAt($rows, $headerRow)];
    }

    /** Lo que se le muestra al administrador para confirmar el formato: cuántos ingresos y egresos, y unos ejemplos. */
    private function preview(array $parsed): array
    {
        $dates = array_column($parsed['credits'], 'date');

        return [
            'counts' => ['movimientos' => $parsed['read'], 'ingresos' => count($parsed['credits']), 'egresos' => $parsed['debits']],
            'date_from' => $dates ? min($dates) : null,
            'date_to' => $dates ? max($dates) : null,
            'sample' => array_map(fn ($c) => array_intersect_key($c, array_flip(['line', 'date', 'reference', 'description', 'amount'])), array_slice($parsed['credits'], 0, 5)),
        ];
    }
}
