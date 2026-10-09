<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\MetaAlertRule;
use App\Models\MetaAd;
use App\Models\MetaCampaign;
use App\Models\MetaCreative;
use App\Models\MetaInsightDaily;
use App\Models\Product;
use App\Models\ProductAdTarget;
use App\Services\Meta\MetaHierarchySync;
use App\Services\Meta\MetaVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Lo que el Admin carga a mano en Meta Ads (documento de Fran del 2026-10-08, Módulo 2):
 * - producto y ciudad de cada campaña (§11, §12, §13);
 * - el creative_tracking_id de un anuncio, si la nomenclatura no alcanza (§32), y el estado de cada creativo (§41);
 * - Target CPA y Break-even CPA de cada producto (§34).
 * Solo el Administrador (§44).
 */
class MetaAdsSetupController extends Controller
{
    /** Productos y ciudades del sistema, para elegir (§11: "[seleccionar producto]", "[seleccionar ciudad]"). */
    public function options()
    {
        return response()->json([
            'products' => Product::orderByRaw('COALESCE(NULLIF(showable_name, ""), title)')->get(['id', 'title', 'showable_name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title]),
            'cities' => City::orderBy('name')->get(['id', 'name']),
            'creative_statuses' => MetaCreative::STATUSES,
        ]);
    }

    /**
     * Las campañas, primero las que faltan clasificar (§13), con su gasto de los últimos 30 días para saber cuáles
     * importan más. ?pending=1 deja solo las sin clasificar.
     */
    public function campaigns(Request $request)
    {
        $since = now()->subDays(29)->toDateString();
        $spend = MetaInsightDaily::where('level', 'campaign')->where('date', '>=', $since)
            ->groupBy('meta_id')->selectRaw('meta_id, SUM(spend) spend')->pluck('spend', 'meta_id');

        $campaigns = MetaCampaign::with(['account:id,name,meta_id,is_active', 'product:id,title,showable_name', 'city:id,name', 'classifier:id,names'])
            ->whereHas('account', fn ($q) => $q->where('is_active', true))
            ->when($request->boolean('pending'), fn ($q) => $q->where(fn ($w) => $w->whereNull('product_id')->orWhereNull('city_id')))
            ->get()
            ->map(fn (MetaCampaign $c) => [
                'id' => $c->id,
                'meta_id' => $c->meta_id,
                'name' => $c->name,
                'account' => $c->account?->name,
                'effective_status' => $c->effective_status,
                'missing' => $c->missing_since !== null,
                'created_time' => $c->created_time?->toIso8601String(),
                'product_id' => $c->product_id,
                'product' => $c->product ? ($c->product->showable_name ?: $c->product->title) : null,
                'city_id' => $c->city_id,
                'city' => $c->city?->name,
                'classified' => $c->isClassified(),
                'classified_by' => $c->classifier?->names,
                'classified_at' => $c->classified_at?->toIso8601String(),
                'spend_30d' => round((float) ($spend[$c->meta_id] ?? 0), 2),
            ])
            ->sortBy([['classified', 'asc'], ['spend_30d', 'desc']])->values();

        return response()->json(MetaVisibility::forUser(
            ['campaigns' => $campaigns->all(), 'pending' => $campaigns->where('classified', false)->count()], Auth::user()));
    }

    /** §11: "Después de guardar: Campaign ID 238xxxxx = Comprimax / Caracas". Ad sets y anuncios lo heredan (§12). */
    public function classify(Request $request, MetaCampaign $campaign)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'city_id' => 'required|integer|exists:cities,id',
        ]);
        $campaign->forceFill($data + ['classified_by' => Auth::id(), 'classified_at' => now()])->save();

        return response()->json(['status' => true]);
    }

    /**
     * §32: "la relación debe poder corregirse manualmente". Con un ID, queda puesto a mano y la sincronización no lo
     * cambia; vacío, vuelve a detectarse por el nombre.
     */
    public function setTrackingId(Request $request, MetaAd $ad)
    {
        $data = $request->validate(['creative_tracking_id' => 'nullable|string|max:120']);
        $id = isset($data['creative_tracking_id']) ? strtoupper(trim($data['creative_tracking_id'])) : '';

        if ($id === '') {
            $ad->tracking_id_source = null;
        } else {
            $ad->creative_tracking_id = $id;
            $ad->tracking_id_source = 'manual';
        }
        MetaHierarchySync::applyTrackingId($ad);
        $ad->save();

        return response()->json(['creative_tracking_id' => $ad->creative_tracking_id, 'tracking_id_source' => $ad->tracking_id_source]);
    }

    /** §41: estados del creativo asignados a mano, nunca por IA. */
    public function setCreativeStatus(Request $request, MetaCreative $creative)
    {
        $data = $request->validate(['status' => ['nullable', 'string', Rule::in(MetaCreative::STATUSES)]]);
        $creative->forceFill([
            'status' => $data['status'] ?? null, 'status_changed_by' => Auth::id(), 'status_changed_at' => now(),
        ])->save();

        return response()->json(['status' => $creative->status]);
    }

    /** §39 y §40: las reglas de las señales, para cambiarlas sin tocar código. */
    public function rules()
    {
        return response()->json([
            'rules' => MetaAlertRule::orderByRaw("FIELD(type, 'spend_no_purchases', 'limited_sample', 'trend')")->orderBy('threshold')->get(),
            'metrics' => MetaAlertRule::TREND_METRICS,
            'severities' => MetaAlertRule::SEVERITIES,
        ]);
    }

    /** §40: una regla de tendencia nueva (métrica, sube o baja, %, severidad). */
    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'metric' => ['required', Rule::in(MetaAlertRule::TREND_METRICS)],
            'direction' => ['required', Rule::in(['up', 'down'])],
            'threshold' => 'required|numeric|min:0.1|max:1000',
            'severity' => ['required', Rule::in(MetaAlertRule::SEVERITIES)],
        ]);
        // Nace desactivada: el % lo define Fran (§40: "NO hardcodear todavía porcentajes arbitrarios")
        MetaAlertRule::create($data + ['type' => 'trend', 'is_active' => false, 'updated_by' => Auth::id()]);

        return $this->rules();
    }

    public function updateRule(Request $request, MetaAlertRule $rule)
    {
        $data = $request->validate([
            'threshold' => 'nullable|numeric|min:0|max:1000',
            'severity' => ['sometimes', Rule::in(MetaAlertRule::SEVERITIES)],
            'is_active' => 'sometimes|boolean',
            'metric' => ['sometimes', Rule::in(MetaAlertRule::TREND_METRICS)],
            'direction' => ['sometimes', Rule::in(['up', 'down'])],
        ]);
        if ($rule->type !== 'trend') {
            unset($data['metric'], $data['direction']);
        }
        $threshold = array_key_exists('threshold', $data) ? $data['threshold'] : $rule->threshold;
        if (($data['is_active'] ?? $rule->is_active) && $threshold === null) {
            return response()->json(['status' => false, 'message' => 'Para activar la regla hace falta el umbral.'], 422);
        }
        $rule->fill($data + ['updated_by' => Auth::id()])->save();

        return $this->rules();
    }

    /** Solo las de tendencia se borran: las del §39 están en el documento. */
    public function deleteRule(MetaAlertRule $rule)
    {
        if ($rule->type !== 'trend') {
            return response()->json(['status' => false, 'message' => 'Esta regla es del documento: se puede desactivar, no borrar.'], 422);
        }
        $rule->delete();

        return $this->rules();
    }

    /** §34: Target CPA y Break-even por producto, con su historial (§49: vigencia). */
    public function targets()
    {
        $rows = ProductAdTarget::with(['product:id,title,showable_name', 'creator:id,names'])
            ->orderBy('product_id')->orderByDesc('valid_from')->orderByDesc('id')->get();
        $today = now()->toDateString();

        $products = $rows->groupBy('product_id')->map(function ($history) use ($today) {
            $current = $history->first(fn ($t) => $t->valid_from->toDateString() <= $today) ?? $history->last();
            $p = $current->product;
            return [
                'product_id' => $current->product_id,
                'product' => $p ? ($p->showable_name ?: $p->title) : null,
                'target_cpa' => $current->target_cpa !== null ? (float) $current->target_cpa : null,
                'break_even_cpa' => $current->break_even_cpa !== null ? (float) $current->break_even_cpa : null,
                'valid_from' => $current->valid_from->toDateString(),
                'history' => $history->map(fn ($t) => [
                    'target_cpa' => $t->target_cpa !== null ? (float) $t->target_cpa : null,
                    'break_even_cpa' => $t->break_even_cpa !== null ? (float) $t->break_even_cpa : null,
                    'valid_from' => $t->valid_from->toDateString(),
                    'by' => $t->creator?->names,
                ])->values(),
            ];
        })->sortBy('product')->values();

        return response()->json(['targets' => $products]);
    }

    /** Un cambio es una fila nueva, vigente desde la fecha indicada (por defecto, hoy). */
    public function storeTarget(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'target_cpa' => 'nullable|numeric|min:0|max:100000',
            'break_even_cpa' => 'nullable|numeric|min:0|max:100000',
            'valid_from' => 'nullable|date_format:Y-m-d',
        ]);
        if (($data['target_cpa'] ?? null) === null && ($data['break_even_cpa'] ?? null) === null) {
            return response()->json(['status' => false, 'message' => 'Indica el Target CPA, el Break-even o los dos.'], 422);
        }
        ProductAdTarget::updateOrCreate(
            ['product_id' => $data['product_id'], 'valid_from' => $data['valid_from'] ?? now()->toDateString()],
            ['target_cpa' => $data['target_cpa'] ?? null, 'break_even_cpa' => $data['break_even_cpa'] ?? null, 'created_by' => Auth::id()],
        );

        return $this->targets();
    }
}
