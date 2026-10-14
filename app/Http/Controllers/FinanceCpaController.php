<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\FinancialAuditLog;
use App\Models\Product;
use App\Models\ProductFinancialSetting;
use App\Models\Setting;
use App\Models\TargetCpaVersion;
use App\Services\CommissionService;
use App\Services\Finance\CohortReport;
use App\Services\Finance\FinancialCpa;
use App\Services\Finance\OrderEconomics;
use App\Services\Finance\TransferTrips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Finanzas › Cohortes, CPA y Configuración (Módulo 4 de Fran, §59 a §74, §22, §66, R13). Solo el Admin (§81, por la
 * ruta). Cada cambio de configuración o de margen queda en la auditoría financiera (§82).
 */
class FinanceCpaController extends Controller
{
    public function cohorts(Request $request, CohortReport $report): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'group' => ['nullable', Rule::in(['day', 'week'])],
            'product_id' => 'nullable|integer', 'city_id' => 'nullable|integer',
        ]);
        $from = Carbon::parse($data['from'] ?? now()->subDays(30))->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->startOfDay();
        abort_if($from->diffInDays($to) > 400, 422, 'El período no puede pasar de 400 días.');

        return response()->json($report->report($from, $to, $data['group'] ?? 'day', $data['product_id'] ?? null, $data['city_id'] ?? null));
    }

    /** §69, §70, §73: el último CPA de cada producto + ciudad y de cada producto. */
    public function cpa(FinancialCpa $cpa): JsonResponse
    {
        $latest = collect($cpa->latest());
        $products = Product::whereIn('id', $latest->pluck('product_id')->unique())->get(['id', 'title', 'showable_name'])->keyBy('id');
        $cities = City::whereIn('id', $latest->pluck('city_id')->filter()->unique())->pluck('name', 'id');
        [$from, $to] = FinancialCpa::window();

        return response()->json([
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'last_computed_at' => $latest->max(fn ($v) => $v->computed_at)?->format('Y-m-d H:i'),
            'rows' => $latest->sortBy(fn ($v) => [$v->product_id, $v->city_id === null ? 0 : 1, $cities[$v->city_id] ?? ''])->values()
                ->map(fn (TargetCpaVersion $v) => $v->toPayload() + [
                    'product' => ($p = $products->get($v->product_id)) ? ($p->showable_name ?: $p->title) : "Producto {$v->product_id}",
                    'city' => $v->city_id ? ($cities[$v->city_id] ?? "Ciudad {$v->city_id}") : null,
                ]),
        ]);
    }

    public function recalculate(FinancialCpa $cpa): JsonResponse
    {
        $n = $cpa->compute(Auth::id(), 'manual');

        return response()->json(['message' => $n > 0 ? "CPA recalculado: {$n} versiones nuevas." : 'No hay órdenes ni compras de Meta en la ventana madura.']);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => 'required|integer', 'city_id' => 'nullable|integer']);

        return response()->json(['versions' => TargetCpaVersion::where('product_id', $data['product_id'])
            ->when($data['city_id'] ?? null, fn ($q, $c) => $q->where('city_id', $c), fn ($q) => $q->whereNull('city_id'))
            ->orderByDesc('version')->limit(52)->get()->map->toPayload()]);
    }

    /** §60, §22, §66: lo que el Admin configura. */
    public function settings(): JsonResponse
    {
        $margins = ProductFinancialSetting::get()->keyBy('product_id');

        return response()->json([
            'maturity_lag_days' => (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4),
            'analysis_window_days' => (int) Setting::get(FinancialCpa::SETTING_WINDOW, 7),
            'commissions' => CommissionService::amounts(),
            // §15: la regla de desgaste de los viajes de traslado
            'vehicle_wear' => TransferTrips::wearRule(),
            'wear_modes' => TransferTrips::WEAR_MODES,
            'target_types' => ProductFinancialSetting::TYPES,
            'products' => Product::orderBy('title')->get(['id', 'title', 'showable_name', 'cost_usd'])->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->showable_name ?: $p->title, 'cost_usd' => $p->cost_usd !== null ? (float) $p->cost_usd : null,
                'target_type' => $margins->get($p->id)?->target_type ?? ProductFinancialSetting::DEFAULT_TYPE,
                'target_pct' => $margins->get($p->id)?->target_pct ?? ProductFinancialSetting::DEFAULT_PCT,
            ]),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'maturity_lag_days' => 'required|integer|min:0|max:60',
            'analysis_window_days' => 'required|integer|min:1|max:90',
            'commission_order' => 'required|numeric|min:0|max:1000',
            'commission_upsell_unit' => 'required|numeric|min:0|max:1000',
            'commission_deliverer' => 'required|numeric|min:0|max:1000',
            'vehicle_wear_mode' => ['sometimes', Rule::in(array_keys(TransferTrips::WEAR_MODES))],
            'vehicle_wear_value' => ['sometimes', 'numeric', 'min:0', 'max:100000',
                fn ($attr, $value, $fail) => $request->input('vehicle_wear_mode') === 'percent' && $value > 100 ? $fail('El porcentaje de desgaste no puede pasar de 100.') : null],
            'reason' => 'nullable|string|max:500',
        ]);
        $before = $this->currentSettings();
        Setting::set(OrderEconomics::SETTING_MATURITY_LAG, (int) $data['maturity_lag_days']);
        Setting::set(FinancialCpa::SETTING_WINDOW, (int) $data['analysis_window_days']);
        Setting::set(CommissionService::SETTING_ORDER, round((float) $data['commission_order'], 2));
        Setting::set(CommissionService::SETTING_UPSELL_UNIT, round((float) $data['commission_upsell_unit'], 2));
        Setting::set(CommissionService::SETTING_DELIVERER, round((float) $data['commission_deliverer'], 2));
        if (isset($data['vehicle_wear_mode'], $data['vehicle_wear_value'])) {
            // Vale para los viajes que se registren desde ahora; cada viaje guarda la regla con la que se registró
            Setting::set(TransferTrips::SETTING_WEAR_MODE, $data['vehicle_wear_mode']);
            Setting::set(TransferTrips::SETTING_WEAR_VALUE, round((float) $data['vehicle_wear_value'], 4));
        }
        $after = $this->currentSettings();
        if ($after !== $before) {
            FinancialAuditLog::record('finance_settings_updated', 'finance_settings', null, $before, $after, $data['reason'] ?? null);
        }

        return response()->json(['message' => 'Configuración guardada. Las comisiones nuevas valen para las próximas entregas.']);
    }

    /** §66, R13: el margen objetivo de un producto. */
    public function updateMargin(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(array_keys(ProductFinancialSetting::TYPES))],
            'target_pct' => 'required|numeric|min:0|max:500',
            'reason' => 'nullable|string|max:500',
        ]);
        $row = ProductFinancialSetting::firstOrNew(['product_id' => $product->id]);
        $before = ['target_type' => $row->target_type ?? ProductFinancialSetting::DEFAULT_TYPE, 'target_pct' => $row->target_pct ?? ProductFinancialSetting::DEFAULT_PCT];
        $row->fill(['target_type' => $data['target_type'], 'target_pct' => round((float) $data['target_pct'], 2), 'updated_by' => Auth::id()])->save();
        $after = ['target_type' => $row->target_type, 'target_pct' => $row->target_pct];
        if ($after != $before) {
            FinancialAuditLog::record('product_margin_updated', 'product', $product->id, $before, $after, $data['reason'] ?? null);
        }

        return response()->json(['message' => 'Margen objetivo guardado. Vale desde el próximo cálculo del CPA.']);
    }

    private function currentSettings(): array
    {
        return [
            'maturity_lag_days' => (int) Setting::get(OrderEconomics::SETTING_MATURITY_LAG, 4),
            'analysis_window_days' => (int) Setting::get(FinancialCpa::SETTING_WINDOW, 7),
        ] + array_map(fn ($v) => round((float) $v, 2), CommissionService::amounts())
            + ['vehicle_wear' => TransferTrips::wearLabel(TransferTrips::wearRule()['mode'], TransferTrips::wearRule()['value'])];
    }
}
