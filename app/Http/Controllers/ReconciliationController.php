<?php

namespace App\Http\Controllers;

use App\Models\BankStatement;
use App\Models\ReconciliationDay;
use App\Models\ReconciliationEvent;
use App\Models\ReconciliationItem;
use App\Models\StatementSource;
use App\Services\Payments\ReceiptChecker;
use App\Services\Reconciliation\DayBuilder;
use App\Services\Reconciliation\StatementImporter;
use App\Services\Reconciliation\StatementReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Conciliación de pagos digitales con los extractos (documento de Fran del 2026-10-06, Módulo 1). Solo el
 * Administrador (§29): las rutas llevan role:Admin.
 */
class ReconciliationController extends Controller
{
    public function __construct(private DayBuilder $builder)
    {
    }

    /**
     * Para el dashboard (§15): las conciliaciones pendientes. Para el historial (§27): las del mes pedido (?month=AAAA-MM).
     */
    public function index(Request $request)
    {
        $pending = ReconciliationDay::where('status', '!=', ReconciliationDay::COMPLETED)->orderBy('date')->get()
            ->map(fn (ReconciliationDay $d) => $this->dayCard($d));
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->get('month')) ? $request->get('month') : now()->format('Y-m');
        $start = Carbon::parse("{$month}-01");
        $days = ReconciliationDay::whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->orderBy('date')->get()->map(fn (ReconciliationDay $d) => $this->dayCard($d));

        return response()->json(['pending' => $pending, 'month' => $month, 'days' => $days]);
    }

    /**
     * Una conciliación (§16, §24): qué extractos necesita, el resumen y las incidencias. Con ?all=1, también los pagos
     * conciliados (§27).
     */
    public function show(Request $request, string $date)
    {
        $day = $this->day($date);
        $this->builder->build($day->date); // trae lo que haya cambiado desde que se armó (p. ej. una lectura que terminó tarde)
        $day->refresh();

        $items = $day->items()->with(['receipt', 'row.statement:id,original_name', 'resolver:id,names', 'source:id,name'])->orderBy('id')->get();
        $statements = $day->statements()->with(['source:id,name', 'uploader:id,names'])->orderBy('id')->get();
        $needed = $this->builder->neededSources($day);
        $sources = StatementSource::whereIn('id', $needed->merge($statements->pluck('statement_source_id'))->unique())->orderBy('name')->get()
            ->map(function (StatementSource $s) use ($items, $statements, $day) {
                $own = $items->where('statement_source_id', $s->id);
                $current = $statements->first(fn ($st) => $st->statement_source_id === $s->id && !$st->replaced_at);
                $dates = $own->map(fn ($i) => $i->paid_on?->toDateString() ?? $day->date->toDateString())->push($day->date->toDateString());

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'currency' => $s->currency,
                    'payments' => $own->count(),
                    'pending' => $own->where('status', ReconciliationItem::PENDING)->count(),
                    // El extracto tiene que cubrir desde el pago más temprano hasta el día (§16: decir qué archivo necesita)
                    'range' => ['from' => $dates->min(), 'to' => $day->date->toDateString()],
                    'format_saved' => (bool) $s->format,
                    'statement' => $current ? $this->statementInfo($current) : null,
                ];
            })->values();

        $byOrder = $items->groupBy('order_id');
        // §24: las incidencias son lo que falta revisar; lo conciliado o ya resuelto a mano está en "todos" (§27)
        $incidents = $items->whereIn('status', [ReconciliationItem::NOT_FOUND, ReconciliationItem::REVIEW]);
        $shown = $request->boolean('all') ? $items : $incidents;

        return response()->json([
            'day' => $this->dayCard($day),
            'sources' => $sources,
            'summary' => $this->summary($items),
            'items' => $shown->values()->map(fn ($i) => $this->itemView($i, $byOrder->get($i->order_id))),
            'statements' => $statements->map(fn ($s) => $this->statementInfo($s)),
            'events' => ReconciliationEvent::with('user:id,names')->where(function ($q) use ($items, $statements) {
                $q->whereIn('reconciliation_item_id', $items->pluck('id'))->orWhereIn('bank_statement_id', $statements->pluck('id'));
            })->orderByDesc('id')->limit(100)->get()->map(fn ($e) => [
                'action' => $e->action, 'user' => $e->user?->names, 'at' => $e->created_at?->toDateTimeString(),
                'item_id' => $e->reconciliation_item_id, 'statement_id' => $e->bank_statement_id, 'data' => $e->data, 'note' => $e->note,
            ]),
        ]);
    }

    /** Subir (o reemplazar) el extracto de una fuente (§16, §17, §26). */
    public function upload(Request $request, string $date, StatementImporter $importer)
    {
        $request->validate([
            'file' => 'required|file|max:20480',
            'statement_source_id' => 'required|integer|exists:statement_sources,id',
            'format' => 'nullable|string',
            'replace_id' => 'nullable|integer|exists:bank_statements,id',
            'preview' => 'nullable|boolean',
        ]);
        $day = $this->day($date);
        $source = StatementSource::findOrFail($request->integer('statement_source_id'));
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        if (!in_array($extension, StatementReader::EXTENSIONS, true)) {
            return response()->json(['status' => false, 'message' => $extension === 'pdf'
                ? 'El PDF todavía no se puede leer: descarga el extracto en Excel, CSV o TXT.'
                : 'Sube el extracto en Excel (.xlsx o .xls), CSV o TXT.'], 422);
        }
        $replacing = null;
        if ($request->filled('replace_id')) {
            $replacing = BankStatement::current()->where('reconciliation_day_id', $day->id)->where('statement_source_id', $source->id)->find($request->integer('replace_id'));
            if (!$replacing) {
                return response()->json(['status' => false, 'message' => 'El extracto que se quiere reemplazar no es el actual de esta conciliación.'], 422);
            }
        }
        $format = $request->filled('format') ? json_decode($request->input('format'), true) : null;

        try {
            $result = $importer->import($day, $source, $request->file('file'), is_array($format) ? $format : null, Auth::id(), $replacing, $request->boolean('preview'));
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true] + $result);
    }

    /**
     * Resolver a mano una incidencia (§20): "Confirmado manualmente", "Confirmado como no recibido" o "Vinculado
     * manualmente" a un movimiento del extracto. Queda registrado quién, cuándo, qué y sobre qué pago (§28).
     */
    public function resolve(Request $request, ReconciliationItem $item)
    {
        $request->validate([
            'action' => 'required|in:confirm,not_received,link',
            'row_id' => 'required_if:action,link|nullable|integer',
            'note' => 'nullable|string|max:300',
        ]);
        if (!in_array($item->status, [ReconciliationItem::PENDING, ReconciliationItem::NOT_FOUND, ReconciliationItem::REVIEW], true)) {
            return response()->json(['status' => false, 'message' => 'Este pago ya está conciliado o resuelto.'], 422);
        }
        $rowId = null;
        if ($request->input('action') === 'link') {
            $row = $this->rowsFor($item)->firstWhere('id', $request->integer('row_id'));
            if (!$row) {
                return response()->json(['status' => false, 'message' => 'Ese movimiento no está en el extracto de este pago.'], 422);
            }
            if (ReconciliationItem::where('bank_statement_row_id', $row->id)->exists()) {
                return response()->json(['status' => false, 'message' => 'Ese movimiento ya es de otro pago (§21: cada movimiento sirve para un solo pago).'], 422);
            }
            $rowId = $row->id;
        }
        $before = ['estado' => $item->status, 'movimiento' => $item->bank_statement_row_id];
        $status = [
            'confirm' => ReconciliationItem::CONFIRMED,
            'not_received' => ReconciliationItem::NOT_RECEIVED,
            'link' => ReconciliationItem::LINKED,
        ][$request->input('action')];
        $item->forceFill([
            'status' => $status, 'bank_statement_row_id' => $rowId, 'resolved_by' => Auth::id(), 'resolved_at' => now(),
            'note' => $request->input('note'),
        ])->save();
        ReconciliationEvent::log($request->input('action'), Auth::id(), [
            'reconciliation_item_id' => $item->id,
            'data' => ['orden' => $item->order_name, 'monto' => $item->amount, 'moneda' => $item->currency, 'referencia' => $item->reference,
                'antes' => $before, 'despues' => ['estado' => $status, 'movimiento' => $rowId]],
            'note' => $request->input('note'),
        ]);
        $this->builder->refreshStatus($item->day);

        return response()->json(['status' => true]);
    }

    /**
     * Para "Vincular manualmente": los ingresos del extracto de ese pago que coinciden con lo que se busca (?q= una
     * referencia o un monto). Sin búsqueda, los del mismo monto o la misma referencia. Nunca el extracto entero (§23).
     */
    public function rows(Request $request, ReconciliationItem $item)
    {
        $rows = $this->rowsFor($item);
        $q = trim((string) $request->get('q'));
        if ($q !== '') {
            $digits = ltrim(preg_replace('/\D/', '', $q), '0');
            $amount = app(StatementReader::class)->amount(['text' => $q, 'number' => null]);
            $rows = $rows->filter(fn ($r) => ($digits !== '' && str_contains((string) $r->reference_norm, $digits))
                || ($amount !== null && (int) round($r->amount * 100) === (int) round($amount * 100)));
        } else {
            $rows = $rows->filter(fn ($r) => ($item->reference_norm && $r->reference_norm === $item->reference_norm)
                || ($item->amount !== null && (int) round($r->amount * 100) === (int) round($item->amount * 100)));
        }
        $usedBy = ReconciliationItem::whereIn('bank_statement_row_id', $rows->pluck('id'))->pluck('order_name', 'bank_statement_row_id');

        return response()->json(['rows' => $rows->take(30)->values()->map(fn ($r) => [
            'id' => $r->id, 'line' => $r->line, 'date' => $r->date?->toDateString(), 'reference' => $r->reference,
            'amount' => $r->amount, 'description' => $r->description, 'used_by' => $usedBy[$r->id] ?? null,
        ])]);
    }

    /** Los ingresos de los extractos actuales del día donde se busca ese pago (el de su cuenta o los de su método). */
    private function rowsFor(ReconciliationItem $item)
    {
        $sources = $item->statement_source_id ? [$item->statement_source_id]
            : \App\Models\CompanyAccount::where('method', $item->method)->where('is_active', true)->whereNotNull('statement_source_id')->pluck('statement_source_id')->all();
        $statementIds = BankStatement::current()->where('reconciliation_day_id', $item->reconciliation_day_id)->whereIn('statement_source_id', $sources)->pluck('id');

        return \App\Models\BankStatementRow::whereIn('bank_statement_id', $statementIds)->orderBy('line')->get();
    }

    /** El archivo original del extracto (§17: siempre se puede ver de dónde salió cada dato). */
    public function file(BankStatement $statement)
    {
        abort_unless(Storage::disk('local')->exists($statement->path), 404, 'No se encontró el archivo.');

        return Storage::disk('local')->download($statement->path, $statement->original_name);
    }

    // --- Vistas ---

    private function day(string $date): ReconciliationDay
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);

        return ReconciliationDay::whereDate('date', $date)->firstOrFail();
    }

    /** "4 OCT · Pendiente · 3 extractos necesarios", "6 OCT · 1 incidencia" o "Completada" (§15, §27). */
    private function dayCard(ReconciliationDay $day): array
    {
        $items = $day->items()->get(['status']);
        $uploaded = $day->statements()->current()->pluck('statement_source_id');

        return [
            'date' => $day->date->toDateString(),
            'status' => $day->status,
            'payments' => $items->count(),
            'resolved' => $items->whereIn('status', ReconciliationItem::RESOLVED)->count(),
            'incidents' => $items->whereIn('status', [ReconciliationItem::NOT_FOUND, ReconciliationItem::REVIEW])->count(),
            'missing_statements' => $this->builder->neededSources($day, true)->diff($uploaded->map(fn ($id) => (int) $id))->count(),
        ];
    }

    /**
     * El resumen del §24: cuántos pagos hay en cada estado y, por moneda, el monto esperado según los comprobantes, el
     * conciliado y el pendiente de confirmar.
     */
    private function summary($items): array
    {
        $amounts = $items->groupBy(fn ($i) => $i->currency ?: '—')->map(fn ($g, $currency) => [
            'currency' => $currency,
            'expected' => round($g->sum('amount'), 2),
            'conciliated' => round($g->whereIn('status', [ReconciliationItem::MATCHED, ReconciliationItem::LINKED, ReconciliationItem::CONFIRMED])->sum('amount'), 2),
            'not_received' => round($g->where('status', ReconciliationItem::NOT_RECEIVED)->sum('amount'), 2),
            'pending' => round($g->whereIn('status', [ReconciliationItem::PENDING, ReconciliationItem::NOT_FOUND, ReconciliationItem::REVIEW])->sum('amount'), 2),
        ])->values();

        return ['total' => $items->count(), 'by_status' => $items->countBy('status'), 'amounts' => $amounts];
    }

    private function itemView(ReconciliationItem $item, $orderItems): array
    {
        $others = ($orderItems ?? collect())->where('id', '!=', $item->id);

        return [
            'id' => $item->id,
            'status' => $item->status,
            'order_id' => $item->order_id,
            'order_name' => $item->order_name,
            'client_name' => $item->client_name,
            'method' => $item->method,
            'method_label' => ReceiptChecker::KIND_LABEL[$item->method] ?? $item->method,
            'currency' => $item->currency,
            'amount' => $item->amount,
            'reference' => $item->reference,
            'paid_on' => $item->paid_on?->toDateString(),
            'source' => $item->source?->name,
            'match_note' => $item->match_note,
            'candidates' => $item->candidates ?? [],
            'row' => $item->row ? [
                'id' => $item->row->id, 'line' => $item->row->line, 'date' => $item->row->date?->toDateString(), 'reference' => $item->row->reference,
                'amount' => $item->row->amount, 'description' => $item->row->description, 'file' => $item->row->statement?->original_name,
            ] : null,
            'receipt_url' => $item->receipt?->url,
            'resolved_by' => $item->resolver?->names,
            'resolved_at' => $item->resolved_at?->toDateTimeString(),
            'note' => $item->note,
            // §21: los demás pagos de la misma orden en este día, para ver si la orden quedó parcialmente conciliada
            'order_payments' => $others->map(fn ($o) => ['id' => $o->id, 'status' => $o->status, 'amount' => $o->amount, 'currency' => $o->currency])->values(),
        ];
    }

    private function statementInfo(BankStatement $s): array
    {
        return [
            'id' => $s->id,
            'source_id' => $s->statement_source_id,
            'source' => $s->source?->name,
            'file' => $s->original_name,
            'uploaded_by' => $s->uploader?->names,
            'uploaded_at' => $s->created_at?->toDateTimeString(),
            'date_from' => $s->date_from?->toDateString(),
            'date_to' => $s->date_to?->toDateString(),
            'rows_read' => $s->rows_read,
            'rows_credit' => $s->rows_credit,
            'replaced_at' => $s->replaced_at?->toDateTimeString(),
        ];
    }
}
