<?php

namespace App\Console\Commands;

use App\Services\Finance\OrderEconomics;
use Illuminate\Console\Command;

/**
 * Mantiene al día order_financials (Módulo 4 de Fran, entrega 1).
 * - Sin opciones (cada minuto): recalcula las órdenes marcadas por algún cambio.
 * - --mark-all (cada noche): marca todas, por si algún cambio no pasó por los observers (actualizaciones masivas); el
 *   recálculo lo hacen las vueltas siguientes.
 * - --order=ID: recalcula esas órdenes ya (para revisar una).
 */
class FinanceRebuild extends Command
{
    protected $signature = 'finance:rebuild {--mark-all} {--order=*} {--max-time=50}';

    protected $description = 'Recalcula la economía de las órdenes (order_financials, Módulo 4)';

    public function handle(OrderEconomics $economics): int
    {
        if ($this->option('mark-all')) {
            $this->info('Órdenes marcadas para recalcular: ' . OrderEconomics::markAllStale() . '.');

            return self::SUCCESS;
        }
        if ($ids = array_filter(array_map('intval', (array) $this->option('order')))) {
            $this->info('Órdenes recalculadas: ' . $economics->rebuild($ids) . '.');

            return self::SUCCESS;
        }
        $done = $economics->rebuildStale((int) $this->option('max-time'));
        if ($done > 0) {
            $this->info("Órdenes recalculadas: {$done}. Pendientes: " . OrderEconomics::pendingCount() . '.');
        }

        return self::SUCCESS;
    }
}
