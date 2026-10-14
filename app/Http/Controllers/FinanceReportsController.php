<?php

namespace App\Http\Controllers;

use App\Models\FinancialReport;
use App\Models\FinancialReportVersion;
use App\Services\Finance\FinancialReports;
use App\Services\Finance\ReportDelivery;
use App\Services\Finance\ReportExcel;
use App\Services\Finance\ReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Finanzas › Reportes (Módulo 4 de Fran, entrega 4; §53 a §58, §80, R18). Solo el Admin (§81).
 * - Los reportes guardados (automáticos y a mano), con sus versiones (§20, §21).
 * - Generar uno de cualquier rango (§56), actualizarlo, bajarlo en PDF o Excel (§80) y mandarlo por WhatsApp (R18).
 * - La configuración de los automáticos (§53 a §55: "configurable por administración").
 */
class FinanceReportsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(array_keys(FinancialReport::TYPES))], 'page' => 'nullable|integer|min:1']);
        $page = FinancialReport::with(['current', 'creator:id,names'])->withCount('versions')
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('period_to')->orderByDesc('id')->paginate(30);

        return response()->json([
            'reports' => collect($page->items())->map->toPayload()->values(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'types' => FinancialReport::TYPES,
            'config' => FinancialReports::config(),
            'next' => collect([FinancialReport::DAILY, FinancialReport::WEEKLY, FinancialReport::MONTHLY])->mapWithKeys(function ($type) {
                $p = FinancialReports::duePeriod($type, now());

                return [$type => $p ? ['from' => $p[0]->toDateString(), 'to' => $p[1]->toDateString()] : null];
            }),
        ]);
    }

    /** §56: un reporte de cualquier rango. Si ya existe ese período, se recalcula (otra versión si cambió). */
    public function store(Request $request, FinancialReports $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ], ['to.after_or_equal' => 'La fecha final tiene que ser igual o posterior a la inicial.']);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        if ($from->diffInDays($to) > 400) {
            return response()->json(['message' => 'El rango puede tener hasta 400 días.'], 422);
        }
        $result = $reports->generate(FinancialReport::CUSTOM, $from, $to, 'manual', Auth::id(), 'Generado a mano');

        return response()->json([
            'message' => $result['created'] ? 'Reporte generado.' : 'Ese reporte ya existía y sus cifras no cambiaron.',
            'report' => $result['report']->load('current')->loadCount('versions')->toPayload(),
        ], $result['created'] ? 201 : 200);
    }

    /** El reporte: su contenido (la versión pedida o la vigente) y la lista de versiones. */
    public function show(Request $request, FinancialReport $report): JsonResponse
    {
        $number = $request->integer('version') ?: $report->current_version;
        $version = $report->versions()->where('version', $number)->firstOrFail();

        return response()->json([
            'report' => $report->load('current')->loadCount('versions')->toPayload(),
            'version' => $version->toPayload(),
            'content' => $version->content(),
            'versions' => $report->versions()->with('generator:id,names')->get()->map->toPayload()->values(),
        ]);
    }

    /** §20, §21: recalcularlo ahora; si cambió una cifra clave nace otra versión. */
    public function refresh(FinancialReport $report, FinancialReports $reports): JsonResponse
    {
        $version = $reports->refresh($report, 'Actualizado a mano', Auth::id());

        return response()->json(['message' => $version ? "Las cifras cambiaron: versión {$version->version}." : 'Las cifras no cambiaron.']);
    }

    /** §80: el reporte ejecutivo en PDF (de la versión vigente o de la pedida). */
    public function pdf(Request $request, FinancialReport $report, ReportPdf $pdf)
    {
        $version = $this->version($report, $request);

        return Storage::disk('local')->download($pdf->path($version), ReportPdf::filename($version), ['Content-Type' => 'application/pdf']);
    }

    /** §80: el Excel detallado del período del reporte (con los datos de hoy). */
    public function excel(FinancialReport $report, ReportExcel $excel)
    {
        return $this->downloadExcel($excel, $report->period_from, $report->period_to);
    }

    /** §80: el Excel detallado de cualquier rango, sin generar un reporte. */
    public function export(Request $request, ReportExcel $excel)
    {
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        abort_if($from->diffInDays($to) > 400, 422, 'El rango puede tener hasta 400 días.');

        return $this->downloadExcel($excel, $from, $to);
    }

    /** R18: mandarlo ahora por WhatsApp a los números configurados. */
    public function whatsapp(Request $request, FinancialReport $report, ReportDelivery $delivery): JsonResponse
    {
        $results = $delivery->send($this->version($report, $request));
        $ok = collect($results)->where('ok', true)->count();

        return response()->json([
            'message' => $ok === count($results) ? "Enviado a {$ok} número(s)." : "Enviado a {$ok} de " . count($results) . ': ' . collect($results)->where('ok', false)->pluck('error')->unique()->implode(' '),
            'results' => $results,
        ], $ok > 0 ? 200 : 422);
    }

    public function config(): JsonResponse
    {
        return response()->json(['config' => FinancialReports::config()]);
    }

    /** §53 a §55: días, horas y D-N de los automáticos; R18: el envío por WhatsApp. Queda en la auditoría (§82). */
    public function updateConfig(Request $request): JsonResponse
    {
        $time = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'daily.enabled' => 'required|boolean',
            'daily.lag_days' => 'required|integer|min:0|max:30',
            'daily.time' => $time,
            'weekly.enabled' => 'required|boolean',
            'weekly.weekday' => 'required|integer|min:1|max:7',
            'weekly.time' => $time,
            'monthly.enabled' => 'required|boolean',
            'monthly.day' => 'required|integer|min:1|max:28',
            'monthly.time' => $time,
            'whatsapp.enabled' => 'required|boolean',
            'whatsapp.to' => 'nullable|array|max:5',
            'whatsapp.to.*' => ['string', 'regex:/^\+?[\d\s-]{8,20}$/'],
            'whatsapp.template' => 'nullable|string|max:100',
            'whatsapp.language' => 'required|string|max:10',
            'whatsapp.types' => 'nullable|array',
            'whatsapp.types.*' => [Rule::in([FinancialReport::DAILY, FinancialReport::WEEKLY, FinancialReport::MONTHLY])],
        ], [
            '*.time.regex' => 'La hora va como 08:00.',
            'whatsapp.to.*.regex' => 'Cada número va con el código del país, por ejemplo 584141234567.',
        ]);
        if ($data['whatsapp']['enabled'] && (empty($data['whatsapp']['template']) || empty($data['whatsapp']['to']))) {
            return response()->json(['message' => 'Para activar el envío por WhatsApp hacen falta la plantilla y al menos un número.'], 422);
        }
        $data['whatsapp']['to'] ??= [];
        $data['whatsapp']['types'] ??= [];

        return response()->json(['message' => 'Configuración guardada.', 'config' => FinancialReports::saveConfig($data, Auth::id())]);
    }

    private function version(FinancialReport $report, Request $request): FinancialReportVersion
    {
        return $report->versions()->where('version', $request->integer('version') ?: $report->current_version)->firstOrFail();
    }

    private function downloadExcel(ReportExcel $excel, $from, $to)
    {
        $path = $excel->export($from, $to);

        return response()->download($path, ReportExcel::filename($from, $to), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
