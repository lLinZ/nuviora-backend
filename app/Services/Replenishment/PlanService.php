<?php

namespace App\Services\Replenishment;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Genera y guarda el plan de inventario (Módulo 5 de Fran, entrega 3; §4, §96, §106). "Generar plan" junta los datos
 * (PlanData), calcula (PlanEngine) y guarda el resultado completo con la configuración usada y la versión del algoritmo.
 * Un plan guardado nunca se sobrescribe: cada vez que se genera es uno nuevo (§96).
 */
class PlanService
{
    public function __construct(private PlanData $data, private PlanEngine $engine, private ReplenishmentConfig $config)
    {
    }

    /** @return array{id:int} + el plan */
    public function generate(?float $coverageDays = null): array
    {
        $settings = $this->config->settings();
        $coverage = $coverageDays ?? (float) $settings['coverage_days'];
        $input = $this->data->build($coverage);
        $plan = $this->engine->compute($input);
        $plan['pending_settings'] = $this->config->pending();

        $id = DB::transaction(function () use ($plan, $settings, $coverage) {
            $id = DB::table('inventory_forecasts')->insertGetId([
                'user_id' => Auth::id(),
                'coverage_days' => $coverage,
                'safety_days' => (float) $settings['safety_days'],
                'algorithm_version' => PlanEngine::VERSION,
                'settings' => json_encode($settings + ['pending' => $plan['pending_settings']]),
                'result' => json_encode($plan),
                'capital_required_usd' => $plan['capital']['required_usd'],
                'status' => 'generated',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $now = now();
            foreach (array_chunk($plan['lines'], 300) as $chunk) {
                DB::table('inventory_forecast_lines')->insert(array_map(fn ($l) => [
                    'forecast_id' => $id, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'warehouse_id' => $l['warehouse_id'],
                    'city_id' => $l['city_id'], 'physical' => $l['physical'], 'reserved' => $l['reserved'], 'available' => $l['available'],
                    'in_transit' => $l['in_transit'], 'incoming' => $l['incoming'], 'base_daily' => $l['base_daily'], 'forecast_daily' => $l['forecast_daily'],
                    'trend_pct' => $l['trend_pct'], 'target_days' => $l['target_days'], 'coverage_days' => $l['coverage_days'], 'need_units' => $l['need_units'],
                    'light' => $l['light'], 'score' => $l['score'], 'confidence' => $l['confidence'],
                    'detail' => json_encode(['d7' => $l['d7'], 'd14' => $l['d14'], 'd30' => $l['d30'], 'reasons' => $l['reasons'], 'cpa' => $l['cpa'], 'after' => $l['after']]),
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            foreach ($plan['purchases'] as $p) {
                DB::table('forecast_purchase_recommendations')->insert([
                    'forecast_id' => $id, 'product_id' => $p['product_id'], 'variant_id' => $p['variant_id'], 'ideal_qty' => $p['ideal'],
                    'recommended_qty' => $p['recommended'], 'unit_cost_usd' => $p['unit_cost_usd'], 'supplier_id' => $p['supplier_id'],
                    'receiving_warehouse_id' => $p['receiving_warehouse_id'], 'score' => $p['score'], 'reason' => $p['reason'] !== null ? mb_substr($p['reason'], 0, 500) : null,
                    'detail' => json_encode(['explanation' => $p['explanation']]), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ($plan['transfers'] as $t) {
                DB::table('forecast_transfer_recommendations')->insert([
                    'forecast_id' => $id, 'from_warehouse_id' => $t['from_warehouse_id'], 'to_warehouse_id' => $t['to_warehouse_id'],
                    'product_id' => $t['product_id'], 'variant_id' => $t['variant_id'], 'quantity' => $t['quantity'],
                    'depends_on_purchase' => $t['depends_on_purchase'], 'reason' => mb_substr((string) $t['reason'], 0, 300), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return $id;
        });

        return ['id' => $id] + $this->show($id);
    }

    /** Un plan guardado, tal como se generó. */
    public function show(int $id): array
    {
        $row = DB::table('inventory_forecasts as f')->leftJoin('users as u', 'u.id', '=', 'f.user_id')->leftJoin('users as a', 'a.id', '=', 'f.approved_by')
            ->where('f.id', $id)->first(['f.id', 'f.result', 'f.created_at', 'f.algorithm_version', 'f.approved_at', 'f.evaluated_at', 'f.evaluation', 'u.names', 'a.names as approver']);
        abort_if(!$row, 404);
        $plan = json_decode($row->result, true);

        // Entrega 4 (§89): cada recomendación con su id y lo aprobado (se guardaron en el mismo orden que el resultado)
        $recP = DB::table('forecast_purchase_recommendations')->where('forecast_id', $id)->orderBy('id')->get()->values();
        foreach ($plan['purchases'] as $i => &$p) {
            $r = $recP[$i] ?? null;
            $p += ['id' => $r?->id, 'approved_qty' => $r?->approved_qty, 'approved_supplier_id' => $r?->approved_supplier_id,
                'approved_receiving_warehouse_id' => $r?->approved_receiving_warehouse_id, 'approved_unit_cost_usd' => $r?->approved_unit_cost_usd !== null ? (float) $r->approved_unit_cost_usd : null,
                'approved_expected_at' => $r?->approved_expected_at, 'inventory_purchase_id' => $r?->inventory_purchase_id];
        }
        unset($p);
        $recT = DB::table('forecast_transfer_recommendations')->where('forecast_id', $id)->orderBy('id')->get()->values();
        foreach ($plan['transfers'] as $i => &$t) {
            $r = $recT[$i] ?? null;
            $t += ['id' => $r?->id, 'approved_qty' => $r?->approved_qty, 'approved_from_warehouse_id' => $r?->approved_from_warehouse_id,
                'approved_to_warehouse_id' => $r?->approved_to_warehouse_id, 'inventory_transfer_id' => $r?->inventory_transfer_id];
        }
        unset($t);

        return ['id' => (int) $row->id, 'generated_at' => substr((string) $row->created_at, 0, 16), 'generated_by' => $row->names,
            'approved_at' => $row->approved_at ? substr((string) $row->approved_at, 0, 16) : null, 'approved_by' => $row->approver,
            'evaluated_at' => $row->evaluated_at ? substr((string) $row->evaluated_at, 0, 16) : null,
            'evaluation' => $row->evaluation ? json_decode($row->evaluation, true) : null] + $plan;
    }

    /** La lista de planes generados, el más nuevo primero. */
    public function history(int $limit = 50): array
    {
        return DB::table('inventory_forecasts as f')->leftJoin('users as u', 'u.id', '=', 'f.user_id')->orderByDesc('f.id')->limit($limit)
            ->get(['f.id', 'f.created_at', 'f.coverage_days', 'f.safety_days', 'f.capital_required_usd', 'f.algorithm_version', 'u.names'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'generated_at' => substr((string) $r->created_at, 0, 16), 'generated_by' => $r->names,
                'coverage_days' => (float) $r->coverage_days, 'safety_days' => (float) $r->safety_days,
                'capital_required_usd' => (float) $r->capital_required_usd, 'version' => $r->algorithm_version])
            ->all();
    }
}
