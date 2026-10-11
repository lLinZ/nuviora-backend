<?php

namespace App\Http\Controllers;

use App\Models\AgencyClosureSetting;
use App\Models\AgencyWeeklyClosure;
use App\Services\Delivery\Closures\ClosureProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * "Cierres / Servicios" de la agencia (Módulo 3 de Fran, §22, §23 y §38). La agencia ve sus semanas, en qué punto está
 * cada una y sube su archivo. No ve nada interno (§38): ni el cálculo, ni la distancia, ni el tarifario, ni las
 * discrepancias, ni el monto final (respuesta de Fran a la pregunta 5).
 */
class MyClosureController extends Controller
{
    /** §22: el texto del aviso, tal cual el documento. */
    public const REMINDER = [
        'title' => 'Cierre semanal pendiente',
        'text' => 'Hoy corresponde realizar el cierre de la semana anterior. Por favor, sube el archivo de cierre a la brevedad posible para que podamos revisarlo y procesar tu pago.',
    ];

    public function index()
    {
        $agencyId = (int) Auth::id();
        $setting = AgencyClosureSetting::for($agencyId);
        $closures = AgencyWeeklyClosure::with('currentFile')
            ->where('agency_id', $agencyId)->orderByDesc('period_start')->limit(26)->get();

        $list = $closures->map(fn (AgencyWeeklyClosure $c) => [
            'id' => $c->id,
            'week' => $c->weekLabel(),
            'period_start' => $c->period_start->toDateString(),
            'period_end' => $c->period_end->toDateString(),
            'status' => $c->status === AgencyWeeklyClosure::PROCESSING ? AgencyWeeklyClosure::FILE_RECEIVED : $c->status,
            'status_label' => AgencyWeeklyClosure::AGENCY_LABEL[$c->status] ?? $c->status,
            'can_upload' => $this->canUpload($c),
            // Si el archivo no se pudo leer, la agencia lo sabe para subir otro (sin el detalle interno)
            'needs_new_file' => $c->status === AgencyWeeklyClosure::FILE_RECEIVED && $c->processing_error !== null,
            'file_name' => $c->currentFile?->original_name,
            'uploaded_at' => $c->uploaded_at?->toDateTimeString(),
            'paid_at' => $c->paid_at?->toDateString(),
            'reminder' => $c->reminderDue($setting),
        ]);
        $due = $list->where('reminder', true)->values();

        return response()->json([
            'closures' => $list,
            'reminder' => $due->isNotEmpty() ? self::REMINDER + ['weeks' => $due->pluck('week')->all()] : null,
        ]);
    }

    /** §23: la agencia sube su propio archivo. */
    public function upload(Request $request, AgencyWeeklyClosure $closure, ClosureProcessor $processor)
    {
        abort_unless((int) $closure->agency_id === (int) Auth::id(), 404);
        $request->validate(['file' => 'required|file|max:10240']);
        if (!$this->canUpload($closure)) {
            return response()->json(['status' => false, 'message' => 'Ya recibimos el archivo de esa semana. Si necesitas cambiarlo, escríbele a administración.'], 422);
        }
        try {
            $processor->upload($closure, $request->file('file'), (int) Auth::id(), true);
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => 'Archivo recibido. Gracias.']);
    }

    /** Mientras falte el archivo, o si el que subió no se pudo leer. Después, lo cambia administración (§39). */
    private function canUpload(AgencyWeeklyClosure $c): bool
    {
        return $c->status === AgencyWeeklyClosure::PENDING_FILE
            || ($c->status === AgencyWeeklyClosure::FILE_RECEIVED && $c->processing_error !== null);
    }
}
