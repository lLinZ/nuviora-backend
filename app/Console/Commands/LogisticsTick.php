<?php

namespace App\Console\Commands;

use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Services\Logistics\Alerts;
use App\Services\Logistics\Centers;
use App\Services\Logistics\Dispatcher;
use App\Services\Logistics\LogisticsOrders;
use App\Services\Logistics\MorningCheck;
use App\Services\Logistics\OperatorCalendar;
use App\Services\Logistics\Returns;
use App\Services\Logistics\RiderCash;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * El reloj de la logística propia (§33: "los temporizadores deben calcularse en backend"). Cada pasada mira lo que venció
 * según las marcas guardadas, así que un reinicio no pierde nada:
 * - SIN OPERADOR: dentro del horario y sin turno abierto, alerta crítica al Admin (§4.2 punto 4, §25);
 * - las órdenes que esperaban ventana se sueltan cuando el centro ya puede (§4.2 punto 5);
 * - las rondas de las órdenes que buscan rider: R1, R2, el minuto 3 y el paso a la agencia en el minuto 5 (§8, R4);
 * - el chequeo de la mañana de los riders (§6), una vez por día;
 * - los retornos que vencieron el plazo (§16.1, R7): alerta al operador;
 * - los riders con USD 60 o más en efectivo: la alerta sigue abierta hasta que entreguen (§17, §25).
 * Con --loop corre sin parar bajo supervisor, una pasada cada 2 s (las rondas son de 90 s y el scheduler va de minuto en
 * minuto); sin --loop hace una sola. Dos pasadas nunca corren a la vez.
 */
class LogisticsTick extends Command
{
    protected $signature = 'logistics:tick {--loop : Seguir corriendo} {--every=2 : Segundos entre pasadas} {--max-time=3600 : Segundos antes de salir (supervisor lo vuelve a lanzar)}';

    protected $description = 'Revisa los tiempos de la logística propia (sin operador, órdenes esperando ventana, rondas)';

    public function handle(Centers $centers, LogisticsOrders $orders, Alerts $alerts, Dispatcher $dispatcher): int
    {
        $until = microtime(true) + (int) $this->option('max-time');
        do {
            $started = microtime(true);
            $lock = Cache::lock('logistics:tick-pass', 60);
            if ($lock->get()) {
                try {
                    $this->pass($centers, $orders, $alerts, $dispatcher);
                } catch (\Throwable $e) {
                    report($e);
                } finally {
                    $lock->release();
                }
            }
            if (!$this->option('loop')) {
                break;
            }
            $sleep = max(0.2, (float) $this->option('every') - (microtime(true) - $started));
            usleep((int) ($sleep * 1_000_000));
        } while (microtime(true) < $until);

        return self::SUCCESS;
    }

    private function pass(Centers $centers, LogisticsOrders $orders, Alerts $alerts, Dispatcher $dispatcher): void
    {
        foreach (LogisticsCenter::where('is_active', true)->get() as $center) {
            $state = $centers->state($center);
            if ($state === Centers::NO_OPERATOR) {
                // §4.1: se nombra a quien estaba programado hoy
                $scheduled = app(OperatorCalendar::class)->on($center, now())['name'];
                $alerts->raise($center, 'sin_operador', LogisticsAlert::CRITICAL,
                    "{$center->name}: es hora de abrir y ningún operador inició turno" . ($scheduled ? " (hoy le toca a {$scheduled})" : '')
                    . '. No se despacha nada, tampoco por agencia.',
                    "sin_operador:{$center->id}:" . now()->toDateString());
            }
            if ($state === Centers::OPEN) {
                $orders->releaseWaiting($center);
                $dispatcher->advanceCenter($center->id);
            }
            // §6: el chequeo de la mañana, una vez por día
            app(MorningCheck::class)->runIfDue($center);
            app(Returns::class)->checkOverdue($center);
            app(RiderCash::class)->checkCenter($center);
        }
    }
}
