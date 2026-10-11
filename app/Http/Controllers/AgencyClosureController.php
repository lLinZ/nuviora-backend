<?php

namespace App\Http\Controllers;

use App\Models\AgencyClosureDiscrepancy;
use App\Models\AgencyClosureFile;
use App\Models\AgencyClosureRow;
use App\Models\AgencyClosureSetting;
use App\Models\AgencyTrip;
use App\Models\AgencyWeeklyClosure;
use App\Models\DeliveryAuditLog;
use App\Models\User;
use App\Services\Delivery\Closures\ClosureMatcher;
use App\Services\Delivery\Closures\ClosureProcessor;
use App\Services\Delivery\Closures\ClosureReview;
use App\Jobs\ProcessClosureFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Los cierres semanales de las agencias, del lado de administración (Módulo 3 de Fran, §31 a §37 y §39). Solo el Admin
 * (respuesta de Fran a la pregunta 6); la agencia tiene su propia vista sin nada interno (MyClosureController, §38).
 */
class AgencyClosureController extends Controller
{
    /** §31: la lista de cierres con su resultado. */
    public function index(Request $request)
    {
        $query = AgencyWeeklyClosure::with(['agency:id,names', 'currentFile'])
            ->orderByDesc('period_start')->orderBy('agency_id');
        if ($request->filled('agency_id')) {
            $query->where('agency_id', (int) $request->input('agency_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $settings = AgencyClosureSetting::all()->keyBy('agency_id');

        return response()->json([
            'closures' => $query->limit(200)->get()->map(fn (AgencyWeeklyClosure $c) => $this->present($c, $settings->get($c->agency_id) ?? AgencyClosureSetting::for($c->agency_id))),
            'agencies' => $this->agencies(),
            'statuses' => AgencyWeeklyClosure::STATUS_LABEL,
        ]);
    }

    /** Abrir a mano el cierre de una semana (si la agencia no tuvo carreras registradas o se quiere adelantar). */
    public function store(Request $request)
    {
        $data = $request->validate(['agency_id' => 'required|integer|exists:users,id', 'week' => 'required|date_format:Y-m-d']);
        abort_unless($this->isAgency($data['agency_id']), 422, 'Esa no es una agencia.');
        $start = ClosureProcessor::weekStart($data['week']);
        $closure = AgencyWeeklyClosure::firstOrCreate(
            ['agency_id' => $data['agency_id'], 'period_start' => $start->toDateString()],
            [
                'period_end' => $start->copy()->addDays(6)->toDateString(),
                'status' => AgencyWeeklyClosure::PENDING_FILE,
                'trips_count' => AgencyTrip::where('agency_id', $data['agency_id'])->whereNull('voided_at')
                    ->whereBetween('trip_date', [$start->toDateString(), $start->copy()->addDays(6)->toDateString()])->count(),
            ],
        );

        return response()->json(['status' => true, 'id' => $closure->id, 'created' => $closure->wasRecentlyCreated]);
    }

    /** §31 a §33: el resumen, las discrepancias, todas las filas y las versiones del archivo. */
    public function show(AgencyWeeklyClosure $closure)
    {
        $closure->load(['agency:id,names', 'approver:id,names', 'payer:id,names']);
        $setting = AgencyClosureSetting::for($closure->agency_id);
        $rows = $closure->rows()->with([
            'trip:id,order_id,trip_date,result,type,started_at',
            'order:id,name,location,client_id', 'order.client:id,first_name,last_name,phone',
            'route:id,distance_km,agency_tariff_id,agency_tariff_range_id,calculation_status,error,tariff_date',
            'route.tariff:id,version,valid_from', 'route.range',
            'discrepancy.resolver:id,names',
        ])->get();

        return response()->json([
            'closure' => $this->present($closure, $setting) + [
                'approved_by' => $closure->approver?->names,
                'approved_at' => $closure->approved_at?->toDateTimeString(),
                'paid_by' => $closure->payer?->names,
                'payment' => $closure->paid_at ? [
                    'amount' => $closure->payment_amount, 'date' => $closure->payment_date?->toDateString(), 'method' => $closure->payment_method,
                    'reference' => $closure->payment_reference, 'has_receipt' => (bool) $closure->payment_receipt_path,
                ] : null,
                'tolerance' => ['amount' => $setting->tolerance_amount, 'percent' => $setting->tolerance_percent],
            ],
            'rows' => $rows->map(fn (AgencyClosureRow $r) => $this->presentRow($r)),
            'files' => $closure->files()->with('uploader:id,names')->get()->map(fn (AgencyClosureFile $f) => [
                'id' => $f->id, 'version' => $f->version, 'name' => $f->original_name, 'is_current' => $f->is_current,
                'uploaded_by' => $f->uploader?->names, 'uploaded_at' => $f->uploaded_at?->toDateTimeString(),
                'rows' => $f->rows_count, 'reported_total' => $f->reported_total, 'extraction' => $f->extraction,
            ]),
            'history' => DeliveryAuditLog::with('user:id,names')
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', 'agency_weekly_closure')->where('subject_id', $closure->id))
                    ->orWhere('after->closure_id', $closure->id))
                ->latest('id')->limit(100)->get()
                ->map(fn ($l) => ['action' => $l->action, 'user' => $l->user?->names, 'at' => $l->created_at?->toDateTimeString(), 'before' => $l->before, 'after' => $l->after]),
            'kinds' => AgencyClosureDiscrepancy::KIND_LABEL,
            'resolutions' => AgencyClosureDiscrepancy::RESOLUTION_LABEL,
        ]);
    }

    /** §23: administración también puede subir o reemplazar el archivo (§39). */
    public function upload(Request $request, AgencyWeeklyClosure $closure, ClosureProcessor $processor)
    {
        $request->validate(['file' => 'required|file|max:10240']);
        try {
            $file = $processor->upload($closure, $request->file('file'), Auth::id(), false);
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => "Archivo recibido (versión {$file->version}). Se está procesando."]);
    }

    /** Volver a procesar el archivo vigente (después de corregir el mapeo, o si falló la IA). */
    public function reprocess(AgencyWeeklyClosure $closure)
    {
        if ($closure->status === AgencyWeeklyClosure::PAID || !$closure->currentFile) {
            return response()->json(['status' => false, 'message' => 'No hay archivo para procesar, o el cierre ya está pagado.'], 422);
        }
        DeliveryAuditLog::record('closure_reprocessed', 'agency_weekly_closure', $closure->id, ['status' => $closure->status], null);
        $closure->update(['status' => AgencyWeeklyClosure::FILE_RECEIVED, 'processing_error' => null]);
        ProcessClosureFile::dispatch($closure->id);

        return response()->json(['status' => true, 'message' => 'Se está procesando otra vez.']);
    }

    public function resolve(Request $request, AgencyClosureDiscrepancy $discrepancy, ClosureReview $review)
    {
        $data = $request->validate([
            'option' => 'required|in:system,agency,manual,incident',
            'amount' => 'nullable|numeric|min:0|max:100000',
            'reason' => 'nullable|string|max:500',
        ]);
        try {
            $review->resolve($discrepancy, $data['option'], isset($data['amount']) ? (float) $data['amount'] : null, $data['reason'] ?? null, Auth::id());
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => 'Caso resuelto.']);
    }

    public function reopen(AgencyClosureDiscrepancy $discrepancy, ClosureReview $review)
    {
        try {
            $review->reopen($discrepancy, Auth::id());
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => 'El caso quedó abierto otra vez.']);
    }

    public function note(Request $request, AgencyClosureDiscrepancy $discrepancy, ClosureReview $review)
    {
        $data = $request->validate(['text' => 'required|string|max:1000']);
        $review->addNote($discrepancy, $data['text'], Auth::id(), (string) Auth::user()?->names);

        return response()->json(['status' => true, 'message' => 'Nota guardada.']);
    }

    /** §33: las carreras de la agencia alrededor de esa semana, para enlazar una fila a mano. */
    public function trips(Request $request, AgencyWeeklyClosure $closure)
    {
        $q = trim((string) $request->input('q'));
        $linked = AgencyClosureRow::where('closure_id', $closure->id)->where('is_current', true)->whereNotNull('matched_trip_id')
            ->whereIn('match_status', [AgencyClosureRow::MATCHED, AgencyClosureRow::MANUAL])->pluck('position', 'matched_trip_id');
        $ids = collect($request->input('ids', []))->map(fn ($id) => (int) $id)->filter()->all();
        $query = AgencyTrip::with(['order:id,name,client_id', 'order.client:id,first_name,last_name,phone'])
            ->where('agency_id', $closure->agency_id)->whereNull('voided_at');
        if ($ids) {
            $query->whereIn('id', $ids);
        } else {
            $query->whereBetween('trip_date', [$closure->period_start->copy()->subDays(7)->toDateString(), $closure->period_end->copy()->addDays(7)->toDateString()]);
            if ($q !== '') {
                $query->whereHas('order', fn ($o) => $o->where('name', 'like', "%{$q}%")
                    ->orWhereHas('client', fn ($c) => $c->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")));
            }
        }
        $trips = $query->orderBy('trip_date')->orderBy('id')->limit(40)->get();

        return response()->json(['trips' => $trips->map(function (AgencyTrip $t) use ($linked, $closure) {
            $route = ClosureMatcher::routeOf($t);
            return [
                'id' => $t->id,
                'order_id' => $t->order_id,
                'order_name' => $t->order?->name,
                'client' => trim(($t->order?->client?->first_name ?? '') . ' ' . ($t->order?->client?->last_name ?? '')),
                'phone' => $t->order?->client?->phone,
                'trip_date' => $t->trip_date->toDateString(),
                'in_week' => $t->trip_date->between($closure->period_start, $closure->period_end),
                'result' => $t->result ? (AgencyTrip::RESULTS[$t->result] ?? $t->result) : 'En curso',
                'expected_cost' => $route?->expected_cost,
                'linked_row' => $linked[$t->id] ?? null,
            ];
        })]);
    }

    public function link(Request $request, AgencyClosureRow $row, ClosureReview $review, ClosureMatcher $matcher)
    {
        $data = $request->validate(['trip_id' => 'required|integer|exists:agency_trips,id']);
        try {
            $review->link($row, AgencyTrip::findOrFail($data['trip_id']), Auth::id(), $matcher);
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => 'Fila enlazada.']);
    }

    /** §37: registrar el pago. */
    public function pay(Request $request, AgencyWeeklyClosure $closure, ClosureReview $review)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0|max:1000000',
            'date' => 'required|date_format:Y-m-d',
            'method' => 'nullable|string|max:60',
            'reference' => 'nullable|string|max:120',
            'receipt' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,pdf',
        ]);
        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $request->file('receipt')->store("agency-closures/{$closure->agency_id}/pagos", 'local');
        }
        try {
            $review->pay($closure, $data, Auth::id());
        } catch (RuntimeException $e) {
            if (isset($data['receipt_path'])) {
                Storage::disk('local')->delete($data['receipt_path']);
            }
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => 'Pago registrado.']);
    }

    public function download(AgencyClosureFile $file)
    {
        abort_unless(Storage::disk('local')->exists($file->path), 404, 'No se encontró el archivo.');

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    public function receipt(AgencyWeeklyClosure $closure)
    {
        abort_unless($closure->payment_receipt_path && Storage::disk('local')->exists($closure->payment_receipt_path), 404, 'No hay comprobante.');

        return Storage::disk('local')->download($closure->payment_receipt_path);
    }

    /** §21, §24, §30 y §39: la configuración de cada agencia. */
    public function settings()
    {
        $settings = AgencyClosureSetting::all()->keyBy('agency_id');

        return response()->json([
            'agencies' => $this->agencies()->map(fn ($a) => $a + ['settings' => $this->presentSetting($settings->get($a['id']) ?? AgencyClosureSetting::for($a['id']))]),
            'days' => AgencyClosureSetting::DAYS,
        ]);
    }

    public function updateSettings(Request $request, User $agency)
    {
        abort_unless($this->isAgency($agency->id), 404);
        $data = $request->validate([
            'closing_day' => 'required|integer|between:1,7',
            'closing_time' => 'required|date_format:H:i',
            'reminder_active' => 'required|boolean',
            'tolerance_amount' => 'required|numeric|min:0|max:1000',
            'tolerance_percent' => 'nullable|numeric|min:0|max:100',
            'mapping' => 'nullable|array',
            'mapping.header_row' => 'required_with:mapping|integer|min:0|max:100',
            'mapping.columns' => 'required_with:mapping|array',
            'mapping.columns.*' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,2}$/'],
        ]);
        $setting = AgencyClosureSetting::for($agency->id);
        $before = $this->presentSetting($setting);
        $setting->fill([
            'closing_day' => $data['closing_day'],
            'closing_time' => $data['closing_time'] . ':00',
            'reminder_active' => $data['reminder_active'],
            'tolerance_amount' => round((float) $data['tolerance_amount'], 2),
            'tolerance_percent' => isset($data['tolerance_percent']) ? round((float) $data['tolerance_percent'], 2) : null,
            'updated_by' => Auth::id(),
        ]);
        if (array_key_exists('mapping', $data)) {
            $columns = array_filter(array_map(fn ($c) => $c ? strtoupper($c) : null, array_intersect_key($data['mapping']['columns'] ?? [], array_flip(\App\Services\Delivery\Closures\ClosureFileReader::FIELDS))));
            if ($data['mapping'] !== null && !isset($columns['monto'])) {
                return response()->json(['status' => false, 'message' => 'El mapeo necesita al menos la columna del monto.'], 422);
            }
            // Un mapeo a mano vale para cualquier archivo de la agencia (sin comparar los títulos)
            $setting->file_mapping = $data['mapping'] === null ? null : [
                'header_row' => (int) $data['mapping']['header_row'], 'columns' => $columns, 'signature' => null, 'source' => 'manual', 'manual' => true,
            ];
        }
        $setting->save();
        DeliveryAuditLog::record('closure_settings_updated', 'agency', $agency->id, $before, $this->presentSetting($setting));

        return response()->json(['status' => true, 'message' => 'Configuración guardada.', 'settings' => $this->presentSetting($setting)]);
    }

    // --- Presentación ---

    private function present(AgencyWeeklyClosure $c, AgencyClosureSetting $setting): array
    {
        return [
            'id' => $c->id,
            'agency_id' => $c->agency_id,
            'agency' => $c->agency?->names,
            'period_start' => $c->period_start->toDateString(),
            'period_end' => $c->period_end->toDateString(),
            'week' => $c->weekLabel(),
            'status' => $c->status,
            'status_label' => AgencyWeeklyClosure::STATUS_LABEL[$c->status] ?? $c->status,
            'trips_count' => $c->trips_count,
            'expected_total' => $c->expected_total,
            'reported_total' => $c->reported_total,
            'difference' => $c->expected_total !== null && $c->reported_total !== null ? round($c->reported_total - $c->expected_total, 2) : null,
            'final_approved_total' => $c->final_approved_total,
            'summary' => $c->summary,
            'processing_error' => $c->processing_error,
            'uploaded_at' => $c->uploaded_at?->toDateTimeString(),
            'processed_at' => $c->processed_at?->toDateTimeString(),
            'paid_at' => $c->paid_at?->toDateTimeString(),
            'file' => $c->relationLoaded('currentFile') && $c->currentFile ? ['name' => $c->currentFile->original_name, 'version' => $c->currentFile->version] : null,
            'reminder_at' => $c->reminderAt($setting)->toDateTimeString(),
            'reminder_due' => $c->reminderDue($setting),
        ];
    }

    private function presentRow(AgencyClosureRow $r): array
    {
        $d = $r->discrepancy;
        $route = $r->route;

        return [
            'id' => $r->id,
            'position' => $r->position,
            'raw' => $r->raw_data,
            'external_order_id' => $r->external_order_id,
            'repeat_mark' => $r->repeat_mark,
            'client_name' => $r->client_name,
            'phone' => $r->phone,
            'zone' => $r->zone,
            'row_date' => $r->row_date?->toDateString(),
            'reported_cost' => $r->reported_cost,
            'expected_cost' => $r->expected_cost,
            'approved_cost' => $r->approved_cost,
            'match_status' => $r->match_status,
            'match_status_label' => AgencyClosureRow::STATUS_LABEL[$r->match_status] ?? $r->match_status,
            'match_method' => $r->match_method,
            'match_method_label' => $r->match_method ? (AgencyClosureRow::METHOD_LABEL[$r->match_method] ?? $r->match_method) : null,
            'match_note' => $r->match_note,
            'candidate_trip_ids' => $r->candidate_trip_ids ?? [],
            'order' => $r->order ? [
                'id' => $r->order->id, 'name' => $r->order->name,
                'client' => trim(($r->order->client?->first_name ?? '') . ' ' . ($r->order->client?->last_name ?? '')),
                'phone' => $r->order->client?->phone,
                'location' => $r->order->location, // §33: el link del cliente, solo para administración
            ] : null,
            'trip' => $r->trip ? [
                'id' => $r->trip->id, 'date' => $r->trip->trip_date?->toDateString(),
                'type' => AgencyTrip::TYPES[$r->trip->type] ?? $r->trip->type,
                'result' => $r->trip->result ? (AgencyTrip::RESULTS[$r->trip->result] ?? $r->trip->result) : 'En curso',
            ] : null,
            // §33: distancia interna y tarifario aplicado, solo administración
            'route' => $route ? [
                'distance_km' => $route->distance_km,
                'status' => $route->calculation_status,
                'status_label' => \App\Models\OrderDeliveryRoute::STATUS_LABEL[$route->calculation_status] ?? $route->calculation_status,
                'error' => $route->error,
                'tariff_version' => $route->tariff?->version,
                'range' => $route->range?->label(),
                'tariff_date' => $route->tariff_date?->toDateString(),
            ] : null,
            'discrepancy' => $d ? [
                'id' => $d->id,
                'kind' => $d->kind,
                'kind_label' => AgencyClosureDiscrepancy::KIND_LABEL[$d->kind] ?? $d->kind,
                'status' => $d->status,
                'expected_cost' => $d->expected_cost,
                'reported_cost' => $d->reported_cost,
                'difference' => $d->difference,
                'resolution' => $d->resolution,
                'resolution_label' => $d->resolution ? (AgencyClosureDiscrepancy::RESOLUTION_LABEL[$d->resolution] ?? $d->resolution) : null,
                'approved_cost' => $d->approved_cost,
                'reason' => $d->reason,
                'resolved_by' => $d->resolver?->names,
                'resolved_at' => $d->resolved_at?->toDateTimeString(),
                'notes' => $d->notes ?? [],
            ] : null,
        ];
    }

    private function presentSetting(AgencyClosureSetting $s): array
    {
        return [
            'closing_day' => (int) $s->closing_day,
            'closing_time' => $s->timeLabel(),
            'reminder_active' => (bool) $s->reminder_active,
            'tolerance_amount' => (float) $s->tolerance_amount,
            'tolerance_percent' => $s->tolerance_percent !== null ? (float) $s->tolerance_percent : null,
            'mapping' => $s->file_mapping,
        ];
    }

    private function agencies()
    {
        return User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->orderBy('names')->get(['id', 'names'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->names]);
    }

    private function isAgency(int $userId): bool
    {
        return User::whereKey($userId)->whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->exists();
    }
}
