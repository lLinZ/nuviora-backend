<?php

namespace App\Jobs;

use App\Models\AgencyWeeklyClosure;
use App\Services\Delivery\Closures\ClosureProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Procesa el archivo de cierre de una agencia (Módulo 3 de Fran, §24 a §35). Va por su propia cola, "closures", porque
 * la IA puede tardar un par de minutos con un PDF largo y no debe frenar el cálculo de las rutas. Si falla, el cierre
 * queda con el error y el Admin lo vuelve a procesar desde la pantalla.
 */
class ProcessClosureFile implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 420;
    public int $uniqueFor = 900;

    public function __construct(public int $closureId)
    {
        $this->onQueue('closures');
    }

    public function uniqueId(): string
    {
        return (string) $this->closureId;
    }

    public function handle(ClosureProcessor $processor): void
    {
        if ($closure = AgencyWeeklyClosure::find($this->closureId)) {
            $processor->process($closure);
        }
    }
}
