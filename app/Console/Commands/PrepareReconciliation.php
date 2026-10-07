<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\DayBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cada mañana arma la conciliación del día anterior (documento de Fran del 2026-10-06, §12): qué pagos digitales se
 * registraron y qué extractos hay que subir.
 *   php artisan reconciliation:prepare                     la de ayer (la corre el scheduler a las 06:00)
 *   php artisan reconciliation:prepare --date=2026-10-05   la de otro día
 */
class PrepareReconciliation extends Command
{
    protected $signature = 'reconciliation:prepare {--date= : AAAA-MM-DD; por defecto, ayer}';

    protected $description = 'Arma la conciliación de pagos digitales de un día';

    public function handle(DayBuilder $builder): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subDay();
        $day = $builder->build($date);
        if (!$day) {
            $this->info("El {$date->toDateString()} no hubo pagos digitales: no hay conciliación.");

            return self::SUCCESS;
        }
        $this->info("Conciliación del {$day->date->toDateString()}: {$day->items()->count()} pagos, estado {$day->status}.");

        return self::SUCCESS;
    }
}
