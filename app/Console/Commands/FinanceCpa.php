<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancialCpa;
use Illuminate\Console\Command;

/** CPA financiero con datos maduros (Módulo 4 de Fran, §61): los miércoles, con el cierre semanal; no cada pocos minutos. */
class FinanceCpa extends Command
{
    protected $signature = 'finance:cpa';

    protected $description = 'Calcula el CPA financiero (Target, Break-even y Fully Loaded) por producto y ciudad (Módulo 4)';

    public function handle(FinancialCpa $cpa): int
    {
        [$from, $to] = FinancialCpa::window();
        $this->info("Ventana madura {$from->toDateString()} a {$to->toDateString()}: " . $cpa->compute() . ' versiones nuevas.');

        return self::SUCCESS;
    }
}
