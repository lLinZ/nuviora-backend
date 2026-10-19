<?php

namespace App\Console\Commands;

use App\Services\Replenishment\PlanEvaluator;
use Illuminate\Console\Command;

/**
 * Autoevaluación del plan de inventario (Módulo 5 de Fran, §98): cada mañana evalúa los planes cuyo horizonte terminó
 * ayer. Solo mide y muestra; no cambia la configuración (§105).
 */
class ReplenishmentEvaluate extends Command
{
    protected $signature = 'replenishment:evaluate {--plan= : Evaluar solo este plan}';

    protected $description = 'Compara lo previsto por los planes de inventario con lo que de verdad se entregó (Módulo 5)';

    public function handle(PlanEvaluator $evaluator): int
    {
        if ($this->option('plan')) {
            $r = $evaluator->evaluate((int) $this->option('plan'));
            $this->info($r['evaluated'] ? "Plan #{$this->option('plan')} evaluado." : $r['reason']);

            return self::SUCCESS;
        }
        $done = $evaluator->evaluateDue();
        $this->info(count($done) . ' planes evaluados' . ($done ? ': #' . implode(', #', $done) : '') . '.');

        return self::SUCCESS;
    }
}
