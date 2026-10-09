<?php

namespace App\Services\Meta;

use App\Models\MetaAlertRule;
use Illuminate\Support\Collection;

/**
 * Señales de Meta Ads con reglas matemáticas, sin IA (documento de Fran del 2026-10-08, Módulo 2, §36: permitido
 * "Frequency = 1,72 → posible saturación"; no permitido "Apaga C004 porque está saturado"). Son solo visuales: nada se
 * pausa ni se cambia en Meta (§37, §38).
 */
class MetaSignals
{
    private Collection $rules;

    public function __construct(?Collection $rules = null)
    {
        $this->rules = $rules ?? MetaAlertRule::where('is_active', true)->get();
    }

    /** §37: 1,00–1,30 normal; >1,30–1,60 alerta; >1,60–2,00 posible saturación; >2,00 saturación/fatiga elevada. */
    public static function frequency(?float $frequency): ?array
    {
        if ($frequency === null) {
            return null;
        }
        $f = round($frequency, 2);
        return match (true) {
            $f <= 1.30 => ['level' => 'normal', 'color' => 'green', 'label' => 'Normal / ideal'],
            $f <= 1.60 => ['level' => 'alert', 'color' => 'yellow', 'label' => 'Alerta'],
            $f <= 2.00 => ['level' => 'possible_saturation', 'color' => 'orange', 'label' => 'Posible saturación'],
            default => ['level' => 'saturation', 'color' => 'red', 'label' => 'Saturación/fatiga elevada'],
        };
    }

    /** §38: CPA actual contra Target CPA y Break-even CPA. Sin compras no hay CPA (lo cubre el §39). */
    public static function cpa(?float $cpa, ?float $target, ?float $breakEven): ?array
    {
        if ($cpa === null || ($target === null && $breakEven === null)) {
            return null;
        }
        $cpa = round($cpa, 2);
        if ($breakEven !== null && $cpa > $breakEven) {
            return ['level' => 'above_break_even', 'color' => 'red', 'label' => 'Por encima de break-even'];
        }
        if ($target !== null && $cpa > $target) {
            return ['level' => 'above_target', 'color' => 'orange',
                'label' => $breakEven !== null ? 'Por encima del objetivo, pero todavía debajo de BE' : 'Por encima del objetivo'];
        }
        if ($target !== null) {
            return ['level' => 'within_target', 'color' => 'green', 'label' => 'Dentro del objetivo'];
        }
        return ['level' => 'below_break_even', 'color' => 'green', 'label' => 'Debajo de break-even'];
    }

    /**
     * Las reglas configurables (§39, §40) sobre una fila.
     *
     * @param array $totals los valores base del período (spend, purchases…)
     * @param array $metrics las fórmulas del período (MetaMetrics::compute)
     * @param array|null $previous las fórmulas del período anterior, para las tendencias (§28, §40)
     * @return array<int, array{type: string, severity: string, label: string}>
     */
    public function evaluate(array $totals, array $metrics, ?array $previous, ?float $targetCpa): array
    {
        $spend = (float) ($totals['spend'] ?? 0);
        $purchases = (float) ($totals['purchases'] ?? 0);
        $signals = [];

        // §39: gasto ≥ N × Target CPA y 0 compras. Se queda la más grave que se cumpla.
        if ($targetCpa && $purchases == 0.0) {
            $hit = $this->rules->where('type', 'spend_no_purchases')->filter(fn ($r) => $r->threshold !== null && $spend >= $r->threshold * $targetCpa)
                ->sortByDesc('threshold')->first();
            if ($hit) {
                $signals[] = ['type' => 'spend_no_purchases', 'severity' => $hit->severity,
                    'label' => sprintf('Gasto ≥ %s × Target CPA sin compras', rtrim(rtrim(number_format($hit->threshold, 2, ',', ''), '0'), ','))];
            }
        }

        // §39: "muestra todavía limitada aunque exista alguna compra"
        foreach ($this->rules->where('type', 'limited_sample') as $r) {
            if ($r->threshold !== null && $purchases > 0 && $purchases < $r->threshold) {
                $signals[] = ['type' => 'limited_sample', 'severity' => $r->severity,
                    'label' => sprintf('Muestra limitada: %s compras (menos de %s)', $purchases + 0, $r->threshold + 0)];
            }
        }

        // §40: tendencias contra el período anterior
        if ($previous !== null) {
            foreach ($this->rules->where('type', 'trend') as $r) {
                $now = $metrics[$r->metric] ?? $totals[$r->metric] ?? null;
                $before = $previous[$r->metric] ?? null;
                $change = MetaMetrics::change($now !== null ? (float) $now : null, $before !== null ? (float) $before : null);
                if ($change === null || $r->threshold === null) {
                    continue;
                }
                if (($r->direction === 'up' && $change >= $r->threshold) || ($r->direction === 'down' && $change <= -$r->threshold)) {
                    $signals[] = ['type' => 'trend', 'severity' => $r->severity,
                        'label' => sprintf('%s %s %s %%', self::metricLabel($r->metric), $r->direction === 'up' ? 'aumentando' : 'disminuyendo',
                            ($change > 0 ? '+' : '') . number_format($change, 1, ',', '.'))];
                }
            }
        }

        return $signals;
    }

    public static function metricLabel(string $metric): string
    {
        return [
            'cpa' => 'CPA', 'cpm' => 'CPM', 'ctr' => 'CTR', 'link_ctr' => 'Link CTR', 'cpc' => 'CPC', 'frequency' => 'Frequency',
            'hook_rate' => 'Hook Rate', 'hold_rate' => 'Hold Rate', 'lpv_rate' => 'LPV Rate', 'conversion_rate' => 'Conversion Rate',
            'spend' => 'Gasto', 'purchases' => 'Purchases',
        ][$metric] ?? $metric;
    }
}
