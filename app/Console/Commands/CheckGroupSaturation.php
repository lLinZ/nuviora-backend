<?php

namespace App\Console\Commands;

use App\Services\SalesGroups\SaturationMonitor;
use Illuminate\Console\Command;

/** Revisa cada minuto si alguna vendedora está saturada respecto de su grupo (spec de la Líder §9). */
class CheckGroupSaturation extends Command
{
    protected $signature = 'groups:check-saturation';

    protected $description = 'Avisa a la Líder y a Administración cuando una vendedora pasa el 130 % de la carga promedio de su grupo';

    public function handle(SaturationMonitor $monitor): int
    {
        $result = $monitor->check();
        if ($result['opened'] || $result['cleared']) {
            $this->info("Alertas nuevas: {$result['opened']} · cerradas: {$result['cleared']}");
        }

        return self::SUCCESS;
    }
}
