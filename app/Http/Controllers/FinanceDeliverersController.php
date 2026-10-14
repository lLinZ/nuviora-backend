<?php

namespace App\Http\Controllers;

use App\Models\AgencyTariff;
use App\Models\DelivererCostSetting;
use App\Models\FinancialAuditLog;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Finanzas › Configuración › Repartidores propios (Módulo 4 de Fran, R11): cuánto cobra cada uno por entrega, un monto
 * fijo o su tarifario por distancia desde un almacén. Solo el Admin (§81); cada cambio queda en la auditoría (§82).
 */
class FinanceDeliverersController extends Controller
{
    public function index(): JsonResponse
    {
        $deliverers = User::whereHas('role', fn ($q) => $q->where('description', 'Repartidor'))->orderBy('names')->get(['id', 'names']);
        $settings = DelivererCostSetting::whereIn('user_id', $deliverers->pluck('id'))->get()->keyBy('user_id');
        $tariffCities = AgencyTariff::with('city:id,name')->whereIn('agency_id', $deliverers->pluck('id'))->where('is_active', true)->get()
            ->groupBy('agency_id')->map(fn ($rows) => $rows->pluck('city.name')->filter()->unique()->values());

        return response()->json([
            'general_amount' => CommissionService::amounts()['deliverer'],
            'modes' => DelivererCostSetting::MODES,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'latitude', 'longitude'])
                ->map(fn (Warehouse $w) => ['id' => $w->id, 'name' => $w->name, 'has_coordinates' => $w->hasCoordinates()])->values(),
            'deliverers' => $deliverers->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->names,
                'mode' => $settings->get($u->id)?->mode ?? DelivererCostSetting::FIXED,
                'fixed_amount' => $settings->get($u->id)?->fixed_amount,
                'warehouse_id' => $settings->get($u->id)?->warehouse_id,
                'tariff_cities' => $tariffCities->get($u->id, collect())->all(),
            ])->values(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role?->description === 'Repartidor', 404);
        $data = $request->validate([
            'mode' => ['required', Rule::in(array_keys(DelivererCostSetting::MODES))],
            'fixed_amount' => 'nullable|numeric|min:0|max:1000',
            'warehouse_id' => 'nullable|required_if:mode,tariff|integer|exists:warehouses,id',
        ], ['warehouse_id.required_if' => 'Con tarifario hay que elegir el almacén de donde sale.']);
        $setting = DelivererCostSetting::firstOrNew(['user_id' => $user->id]);
        $before = $setting->exists ? $setting->only(['mode', 'fixed_amount', 'warehouse_id']) : null;
        $setting->fill([
            'mode' => $data['mode'],
            'fixed_amount' => isset($data['fixed_amount']) ? round((float) $data['fixed_amount'], 2) : null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'updated_by' => Auth::id(),
        ])->save();
        FinancialAuditLog::record('deliverer_cost_updated', 'user', $user->id, $before, $setting->only(['mode', 'fixed_amount', 'warehouse_id']), null, Auth::id());

        return response()->json(['message' => "Guardado: {$user->names} cobra " . ($data['mode'] === DelivererCostSetting::TARIFF ? 'por tarifario.' : 'un monto fijo.')]);
    }
}
