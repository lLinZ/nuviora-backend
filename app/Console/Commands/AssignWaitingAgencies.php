<?php

namespace App\Console\Commands;

use App\Services\Agencies\AgencyRouter;
use Illuminate\Console\Command;

/**
 * Reparte las órdenes en "Pendiente de asignación a agencia" cuando alguna agencia de su ciudad
 * libera cupo (Fran §19). Corre cada minuto; si no hay órdenes esperando, no hace nada.
 */
class AssignWaitingAgencies extends Command
{
    protected $signature = 'orders:assign-agencies';
    protected $description = 'Asigna agencia a las órdenes que esperan porque todas estaban llenas';

    public function handle(AgencyRouter $router): int
    {
        $count = $router->processWaiting();
        if ($count > 0) {
            $this->info("Órdenes asignadas a una agencia: {$count}");
        }

        return self::SUCCESS;
    }
}
