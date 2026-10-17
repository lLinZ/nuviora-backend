<?php

namespace App\Console\Commands;

use App\Services\Agencies\WorkingHours;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fran (2026-10-17): las órdenes que no pasaron a vendedora porque su ciudad estaba apagada o fuera de horario se
 * reparten en cuanto vuelve a trabajar. Corre cada minuto (para el horario); al encender una ciudad o agencia también.
 */
class ReleaseWaitingCityOrders extends Command
{
    protected $signature = 'agencies:release-waiting';

    protected $description = 'Reparte las órdenes que esperaban que su ciudad volviera a trabajar';

    public function handle(WorkingHours $hours): int
    {
        $released = $hours->release();
        if ($released > 0) {
            Log::info("agencies:release-waiting: {$released} órdenes repartidas al volver a trabajar su ciudad.");
        }

        return self::SUCCESS;
    }
}
