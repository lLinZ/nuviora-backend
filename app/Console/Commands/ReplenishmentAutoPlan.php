<?php

namespace App\Console\Commands;

use App\Models\ReplenishmentRoute;
use App\Services\Replenishment\PlanService;
use App\Services\Replenishment\ReplenishmentConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Plan automático antes de cada día de ruta (Módulo 5 de Fran, §66, §107): "la arquitectura debe quedar preparada", pero
 * no es requisito inicial. Viene apagado; si el Admin lo enciende, la noche antes de un día habitual de alguna ruta activa
 * genera el plan solo (no lo aprueba: aprobar sigue siendo del Admin, §89, §130). Hoy las rutas no tienen días fijos (R6).
 */
class ReplenishmentAutoPlan extends Command
{
    protected $signature = 'replenishment:auto-plan';

    protected $description = 'Genera el plan de inventario la noche antes de un día de ruta, si está activado (Módulo 5)';

    public function handle(ReplenishmentConfig $config, PlanService $plans): int
    {
        if (!(int) $config->settings()['auto_plan_before_route']) {
            $this->info('El plan automático está apagado.');

            return self::SUCCESS;
        }
        $tomorrow = (int) now()->addDay()->isoWeekday();
        $routes = ReplenishmentRoute::where('is_active', true)->get()
            ->filter(fn ($r) => in_array($tomorrow, array_map('intval', (array) ($r->usual_days ?? [])), true));
        if ($routes->isEmpty()) {
            $this->info('Mañana no hay ruta.');

            return self::SUCCESS;
        }
        if (DB::table('inventory_forecasts')->whereNull('user_id')->whereDate('created_at', today())->exists()) {
            $this->info('El plan automático de hoy ya se generó.');

            return self::SUCCESS;
        }
        $plan = $plans->generate();
        $this->info("Plan #{$plan['id']} generado para la ruta de mañana (" . $routes->pluck('name')->implode(', ') . ').');

        return self::SUCCESS;
    }
}
