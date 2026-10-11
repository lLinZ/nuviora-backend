<?php

namespace App\Console\Commands;

use App\Services\Delivery\Closures\ClosureProcessor;
use Illuminate\Console\Command;

/**
 * Abre los cierres semanales de las agencias (Módulo 3 de Fran, §20): cuando termina la semana de lunes a domingo, uno
 * por cada agencia que tuvo carreras. El aviso del §22 no necesita nada más: la agencia lo ve en su panel desde el día y
 * la hora configurados hasta que sube el archivo.
 */
class DeliveryClosures extends Command
{
    protected $signature = 'delivery:closures {--weeks=1 : cuántas semanas terminadas revisar}';

    protected $description = 'Abre los cierres semanales de las agencias con carreras (Módulo 3)';

    public function handle(ClosureProcessor $processor): int
    {
        $opened = $processor->openWeeks(max(1, (int) $this->option('weeks')));
        $this->info("Cierres abiertos: {$opened}.");

        return self::SUCCESS;
    }
}
