<?php

namespace App\Services\Delivery\Closures;

use App\Jobs\ProcessClosureFile;
use App\Models\AgencyClosureDiscrepancy;
use App\Models\AgencyClosureFile;
use App\Models\AgencyClosureRow;
use App\Models\AgencyClosureSetting;
use App\Models\AgencyTrip;
use App\Models\AgencyWeeklyClosure;
use App\Models\DeliveryAuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Los cierres semanales de las agencias (Módulo 3 de Fran):
 *  - se abre uno por agencia y semana de lunes a domingo, cuando la semana termina y la agencia tuvo carreras (§20);
 *  - la agencia (o administración) sube el archivo; cada archivo nuevo es una versión y la anterior queda guardada (§23,
 *    §40);
 *  - procesarlo: leer las filas (§24, §25), normalizarlas (§26), emparejarlas (§27, §28) y compararlas (§29 a §35).
 */
class ClosureProcessor
{
    public function __construct(private ClosureFileReader $reader, private ClosureMatcher $matcher, private ClosureReview $review)
    {
    }

    /** El lunes de la semana de esa fecha. */
    public static function weekStart(Carbon|string $date): Carbon
    {
        return Carbon::parse($date, config('app.timezone'))->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    /**
     * §20: abre los cierres de las semanas ya terminadas (por defecto, la anterior) para las agencias que tuvieron
     * carreras. No toca los que ya existen. Devuelve cuántos abrió.
     */
    public function openWeeks(int $weeksBack = 1, ?Carbon $today = null): int
    {
        $today ??= now(config('app.timezone'));
        $opened = 0;
        for ($i = 1; $i <= $weeksBack; $i++) {
            $start = self::weekStart($today)->subWeeks($i);
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY);
            $counts = AgencyTrip::whereNull('voided_at')->whereBetween('trip_date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('agency_id')->selectRaw('agency_id, count(*) as n')->pluck('n', 'agency_id');
            foreach ($counts as $agencyId => $n) {
                $closure = AgencyWeeklyClosure::firstOrCreate(
                    ['agency_id' => $agencyId, 'period_start' => $start->toDateString()],
                    ['period_end' => $end->toDateString(), 'status' => AgencyWeeklyClosure::PENDING_FILE, 'trips_count' => $n],
                );
                if ($closure->wasRecentlyCreated) {
                    $opened++;
                } elseif ($closure->trips_count !== (int) $n && $closure->status !== AgencyWeeklyClosure::PAID) {
                    $closure->update(['trips_count' => $n]);
                }
            }
        }

        return $opened;
    }

    /** §23: guarda una versión nueva del archivo y lo manda a procesar. La anterior queda, ya no vigente (§40). */
    public function upload(AgencyWeeklyClosure $closure, UploadedFile $file, int $userId, bool $byAgency): AgencyClosureFile
    {
        if ($closure->status === AgencyWeeklyClosure::PAID) {
            throw new RuntimeException('Ese cierre ya está pagado.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ClosureFileReader::EXTENSIONS, true)) {
            throw new RuntimeException('Sube el cierre en Excel (.xlsx o .xls), CSV o PDF.');
        }

        return DB::transaction(function () use ($closure, $file, $userId, $byAgency, $extension) {
            $closure = AgencyWeeklyClosure::whereKey($closure->id)->lockForUpdate()->first();
            $previous = $closure->currentFile;
            $version = (int) $closure->files()->max('version') + 1;
            $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'cierre';
            $path = $file->storeAs("agency-closures/{$closure->agency_id}/{$closure->period_start->toDateString()}", "v{$version}-{$name}.{$extension}", 'local');

            AgencyClosureFile::where('closure_id', $closure->id)->update(['is_current' => false]);
            AgencyClosureRow::where('closure_id', $closure->id)->update(['is_current' => false]);
            AgencyClosureDiscrepancy::where('closure_id', $closure->id)->update(['is_current' => false]);
            $record = AgencyClosureFile::create([
                'closure_id' => $closure->id,
                'version' => $version,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'is_current' => true,
                'uploaded_by' => $userId,
                'uploaded_at' => now(),
            ]);
            $closure->update([
                'status' => AgencyWeeklyClosure::FILE_RECEIVED,
                'uploaded_at' => now(),
                'processing_error' => null,
                'expected_total' => null,
                'reported_total' => null,
                'final_approved_total' => null,
                'manual_total' => null,
                'manual_reason' => null,
                'approved_at' => null,
                'approved_by' => null,
                'summary' => null,
            ]);
            DeliveryAuditLog::record($previous ? 'closure_file_replaced' : 'closure_file_uploaded', 'agency_weekly_closure', $closure->id,
                $previous ? ['file_id' => $previous->id, 'version' => $previous->version, 'name' => $previous->original_name] : null,
                ['file_id' => $record->id, 'version' => $version, 'name' => $record->original_name, 'by_agency' => $byAgency], $userId);
            ProcessClosureFile::dispatch($closure->id);

            return $record;
        });
    }

    /** Procesa el archivo vigente del cierre (lo llama la cola). Si falla, queda "Archivo recibido" con el error. */
    public function process(AgencyWeeklyClosure $closure): void
    {
        $file = $closure->currentFile;
        if (!$file || $closure->status === AgencyWeeklyClosure::PAID) {
            return;
        }
        // Volver a procesar empareja todo otra vez: el otro monto que se había aprobado para el cierre deja de valer
        $closure->update(['status' => AgencyWeeklyClosure::PROCESSING, 'processing_error' => null, 'manual_total' => null, 'manual_reason' => null]);
        $setting = AgencyClosureSetting::for($closure->agency_id);

        try {
            $disk = Storage::disk('local');
            if (!$disk->exists($file->path)) {
                throw new RuntimeException('No se encontró el archivo guardado.');
            }
            $read = $this->reader->read($disk->path($file->path), $file->extension(), $setting);
            if (!$read['rows']) {
                throw new RuntimeException('No se encontraron carreras en el archivo.');
            }
            if ($read['mapping'] && ($setting->file_mapping ?? null) !== $read['mapping']) {
                // §24: el mapeo queda guardado en la agencia, y el Admin lo puede corregir
                $setting->fill(['file_mapping' => $read['mapping']])->save();
            }

            $rows = DB::transaction(function () use ($closure, $file, $read) {
                AgencyClosureRow::where('closure_id', $closure->id)->update(['is_current' => false]);
                AgencyClosureDiscrepancy::where('closure_id', $closure->id)->update(['is_current' => false]);
                return collect($read['rows'])->map(function (array $r) use ($closure, $file) {
                    $order = ClosureNormalizer::orderKey($r['orden']);
                    $phone = ClosureNormalizer::phone($r['telefono']) ?? ClosureNormalizer::phoneIn($r['cliente']) ?? ClosureNormalizer::phoneIn($r['zona']);

                    return AgencyClosureRow::create([
                        'closure_id' => $closure->id,
                        'file_id' => $file->id,
                        'position' => $r['position'],
                        'raw_data' => $r['raw'] + ['monto_texto' => $r['monto_texto'], 'fecha_texto' => $r['fecha_texto']],
                        'external_order_id' => $r['orden'] !== null ? mb_substr($r['orden'], 0, 80) : null,
                        'order_key' => $order['key'],
                        'repeat_mark' => $order['repeat'],
                        'client_name' => $r['cliente'] !== null ? mb_substr($r['cliente'], 0, 160) : null,
                        'client_name_key' => ClosureNormalizer::name($r['cliente']) !== null ? mb_substr(ClosureNormalizer::name($r['cliente']), 0, 160) : null,
                        'phone' => $r['telefono'] !== null ? mb_substr($r['telefono'], 0, 60) : null,
                        'phone_key' => $phone,
                        'zone' => $r['zona'] !== null ? mb_substr($r['zona'], 0, 200) : null,
                        'row_date' => $r['fecha'],
                        'reported_cost' => $r['monto'],
                        'match_status' => AgencyClosureRow::UNMATCHED,
                        'match_note' => $r['nota'],
                        'is_current' => true,
                    ]);
                });
            });

            // La IA puede tardar: se empareja fuera de la transacción
            $match = $this->matcher->match($closure, $rows);

            DB::transaction(function () use ($closure, $file, $read, $rows, $match) {
                $file->update([
                    'extraction' => $read['extraction'] + ['matching' => $match],
                    'rows_count' => $rows->count(),
                    'reported_total' => round($rows->sum(fn ($r) => (float) $r->reported_cost), 2),
                ]);
                $closure->update([
                    'processed_at' => now(),
                    'summary' => array_filter(['ai_note' => $match['ai_note'], 'warnings' => $read['extraction']['warnings'] ?? null]),
                ]);
                $this->review->evaluate($closure->fresh());
            });
        } catch (Throwable $e) {
            if (!$e instanceof RuntimeException) {
                report($e); // un archivo que no se entiende no es un error del sistema: se ve en el cierre
            }
            $closure->update([
                'status' => AgencyWeeklyClosure::FILE_RECEIVED,
                'processing_error' => mb_substr($e instanceof RuntimeException ? $e->getMessage() : 'Error al procesar el archivo.', 0, 500),
            ]);
        }
    }
}
