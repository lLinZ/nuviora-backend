<?php

namespace App\Http\Controllers;

use App\Models\MetaAdAccount;
use App\Models\MetaAdset;
use App\Models\MetaCampaign;
use App\Models\MetaConnection;
use App\Models\MetaCreative;
use App\Services\Meta\MetaAdsReport;
use App\Services\Meta\MetaVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * La pantalla Meta Ads (documento de Fran del 2026-10-08, Módulo 2, §26 a §31, §33, §60): todo desde nuestra base de
 * datos, sin llamar a Meta. Solo el Administrador (§44); las respuestas pasan por el filtro de datos financieros (§45).
 */
class MetaReportController extends Controller
{
    /** §29, §31, §60: por producto → ciudad, con el desglose por cuenta y lo que falta clasificar (§13). */
    public function overview(Request $request)
    {
        $report = $this->report($request);
        $data = $report->overview($request->boolean('compare'));

        return $this->json($data + $this->rangeInfo($report) + [
            'pending_classification' => MetaCampaign::whereHas('account', fn ($q) => $q->where('is_active', true))
                ->where(fn ($q) => $q->whereNull('product_id')->orWhereNull('city_id'))->count(),
            'last_sync_at' => MetaConnection::max('last_success_at'),
            'has_connections' => MetaConnection::exists(),
        ]);
    }

    /** §29: campañas, ad sets, anuncios o creativos (§33) de un filtro (§30). */
    public function rows(Request $request)
    {
        $level = in_array($request->get('level'), ['campaign', 'adset', 'ad', 'creative'], true) ? $request->get('level') : 'campaign';
        $report = $this->report($request);

        return $this->json(['level' => $level, 'rows' => $report->rows($level, $request->boolean('compare'))] + $this->rangeInfo($report));
    }

    /** §26: una métrica en el tiempo. */
    public function series(Request $request)
    {
        $metric = (string) $request->get('metric', 'cpa');
        $report = $this->report($request);

        return $this->json($report->series($metric, $request->boolean('compare')) + $this->rangeInfo($report));
    }

    /** §33 y §60: "entrar en C004": el agregado, los anuncios donde se usa y su estado. */
    public function creative(Request $request, MetaCreative $creative)
    {
        $request->merge(['creative' => $creative->id]);
        $report = $this->report($request);
        $compare = $request->boolean('compare');
        $summary = collect($report->rows('creative', $compare))->firstWhere('id', $creative->id);

        $adsReport = new MetaAdsReport(['creative' => $creative->id, 'include_inactive' => true], $report->range, $report->from, $report->to);

        return $this->json([
            'creative' => ['id' => $creative->id, 'tracking_id' => $creative->tracking_id, 'type' => $creative->type, 'status' => $creative->status,
                'status_changed_at' => $creative->status_changed_at?->toIso8601String()],
            'summary' => $summary,
            'ads' => $adsReport->rows('ad', $compare),
        ] + $this->rangeInfo($report));
    }

    /** Lo que se puede elegir en los filtros (§30). */
    public function filters()
    {
        return $this->json([
            'ranges' => collect(MetaAdsReport::RANGES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'accounts' => MetaAdAccount::where('is_active', true)->orderBy('name')->get(['id', 'name', 'meta_id']),
            'campaigns' => MetaCampaign::whereHas('account', fn ($q) => $q->where('is_active', true))->orderBy('name')
                ->get(['meta_id', 'name', 'product_id', 'city_id', 'effective_status', 'missing_since'])
                ->map(fn ($c) => ['meta_id' => $c->meta_id, 'name' => $c->name, 'product_id' => $c->product_id, 'city_id' => $c->city_id,
                    'active' => $c->effective_status === 'ACTIVE' && $c->missing_since === null]),
            'adsets' => MetaAdset::whereHas('account', fn ($q) => $q->where('is_active', true))->orderBy('name')
                ->get(['meta_id', 'name', 'campaign_meta_id', 'effective_status', 'missing_since'])
                ->map(fn ($s) => ['meta_id' => $s->meta_id, 'name' => $s->name, 'campaign_meta_id' => $s->campaign_meta_id,
                    'active' => $s->effective_status === 'ACTIVE' && $s->missing_since === null]),
            'creatives' => MetaCreative::orderBy('tracking_id')->get(['id', 'tracking_id', 'type', 'status']),
        ]);
    }

    private function report(Request $request): MetaAdsReport
    {
        $int = fn ($k) => $request->filled($k) ? (int) $request->get($k) : null;
        $str = fn ($k) => $request->filled($k) ? (string) $request->get($k) : null;
        $filters = array_filter([
            'product_id' => $int('product_id'),
            'city_id' => $int('city_id'),
            'account_id' => $int('account_id'),
            'campaign' => $str('campaign'),
            'adset' => $str('adset'),
            'ad' => $str('ad'),
            'creative' => $int('creative'),
            'status' => in_array($request->get('status'), ['active', 'inactive'], true) ? $request->get('status') : null,
            'status_creative' => $str('status_creative'),
            'include_inactive' => $request->boolean('include_inactive') ?: null,
        ], fn ($v) => $v !== null);

        return new MetaAdsReport($filters, (string) $request->get('range', 'last_7d'), $str('from'), $str('to'));
    }

    private function rangeInfo(MetaAdsReport $r): array
    {
        return ['range' => ['key' => $r->range, 'label' => MetaAdsReport::rangeLabel($r->range), 'from' => $r->from, 'to' => $r->to,
            'prev_from' => $r->prevFrom, 'prev_to' => $r->prevTo]];
    }

    private function json(array $data)
    {
        return response()->json(MetaVisibility::forUser($data, Auth::user()) + ['can_see_financials' => MetaVisibility::canSeeFinancials(Auth::user())]);
    }
}
