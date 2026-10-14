<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransferTrip;
use App\Services\Finance\TransferTrips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Viajes de traslado (Módulo 4 de Fran, entrega 2c; §13, §14, §15, R8). Solo el Admin (§81, por la ruta). Se registran
 * al hacer los traslados, en Inventario › Operaciones: el viaje agrupa traslados ya hechos y su costo se reparte entre
 * todas las unidades movidas (§14). Nada se borra (§83): se corrige con motivo o se anula.
 */
class FinanceTripController extends Controller
{
    private const MESSAGES = [
        'trip_date.required' => 'Elige la fecha del viaje.',
        'trip_date.before_or_equal' => 'La fecha del viaje no puede ser futura.',
        'movement_ids.required' => 'Marca los traslados que se hicieron en este viaje.',
        'movement_ids.min' => 'Marca los traslados que se hicieron en este viaje.',
        'rate.required_if' => 'En bolívares, escribe la tasa (bolívares por dólar) de este viaje.',
        'reason.required' => 'Escribe el motivo: queda en el historial.',
        'receipt.mimes' => 'El comprobante tiene que ser una imagen o un PDF.',
        'receipt.max' => 'El comprobante no puede pasar de 10 MB.',
    ];

    /** Los viajes de un período, con lo gastado en transporte (§13). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->toDateString();
        $to = Carbon::parse($data['to'] ?? now())->toDateString();
        $trips = InventoryTransferTrip::with(['account:id,name', 'creator:id,names', 'voider:id,names',
            'movements.product:id,title,showable_name', 'movements.variant:id,title', 'movements.fromWarehouse:id,name', 'movements.toWarehouse:id,name'])
            ->whereBetween('trip_date', [$from, $to])->orderByDesc('trip_date')->orderByDesc('id')->get();
        $active = $trips->whereNull('voided_at');

        return response()->json([
            'from' => $from,
            'to' => $to,
            'trips' => $trips->map->toPayload()->values(),
            'summary' => ['trips' => $active->count(), 'units' => (int) $active->sum('units'), 'total_usd' => round($active->sum('total_usd'), 2)],
            'wear' => TransferTrips::wearRule() + ['label' => TransferTrips::wearLabel(TransferTrips::wearRule()['mode'], TransferTrips::wearRule()['value'])],
        ]);
    }

    /** Los traslados que se pueden marcar en un viaje (y los del viaje, si se corrige). */
    public function candidates(Request $request, TransferTrips $trips): JsonResponse
    {
        $trip = $request->filled('trip_id') ? InventoryTransferTrip::find($request->integer('trip_id')) : null;

        return response()->json([
            'movements' => $trips->candidates($trip)->map(fn ($m) => TransferTrips::movementPayload($m))->values(),
            'days' => TransferTrips::CANDIDATE_DAYS,
        ]);
    }

    public function store(Request $request, TransferTrips $trips): JsonResponse
    {
        $data = $request->validate($this->rules(), self::MESSAGES);
        $trip = $trips->create($data, $request->file('receipt'));

        return response()->json(['message' => $this->summary($trip, 'Viaje registrado'), 'trip' => $trip->toPayload()], 201);
    }

    /** POST y no PUT: la corrección puede traer un comprobante nuevo, y PHP no lee archivos en un PUT. */
    public function update(Request $request, InventoryTransferTrip $trip, TransferTrips $trips): JsonResponse
    {
        $data = $request->validate($this->rules() + ['reason' => 'required|string|max:500'], self::MESSAGES);
        $trip = $trips->update($trip, $data, $request->file('receipt'), $data['reason']);

        return response()->json(['message' => $this->summary($trip, 'Viaje corregido'), 'trip' => $trip->toPayload()]);
    }

    public function void(Request $request, InventoryTransferTrip $trip, TransferTrips $trips): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], self::MESSAGES);
        $trips->void($trip, $data['reason']);

        return response()->json(['message' => 'Viaje anulado: sus traslados quedan libres y su costo sale de la logística. Queda en el historial.']);
    }

    public function receipt(InventoryTransferTrip $trip)
    {
        abort_unless($trip->receipt_path && Storage::disk('local')->exists($trip->receipt_path), 404, 'El comprobante ya no está.');

        return Storage::disk('local')->download($trip->receipt_path, "viaje-{$trip->id}." . pathinfo($trip->receipt_path, PATHINFO_EXTENSION));
    }

    private function rules(): array
    {
        return [
            'trip_date' => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:255',
            'currency' => 'required|in:USD,USDT,VES',
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'labor' => 'nullable|numeric|min:0|max:9999999999',
            'fuel' => 'nullable|numeric|min:0|max:9999999999',
            'other' => 'nullable|numeric|min:0|max:9999999999',
            'movement_ids' => 'required|array|min:1',
            'movement_ids.*' => 'integer',
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'notes' => 'nullable|string|max:500',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ];
    }

    private function summary(InventoryTransferTrip $trip, string $what): string
    {
        $total = TransferTrips::usd($trip->total_usd);
        $unit = TransferTrips::usd($trip->unit_cost_usd);

        return "{$what}: {$total} entre {$trip->units} unidades, {$unit} por unidad. Se suma al costo de esas unidades cuando se venden.";
    }
}
