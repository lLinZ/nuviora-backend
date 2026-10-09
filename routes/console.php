<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;
use App\Models\Setting;

try {
    // $open  = Setting::get('business_open_at', '09:00'); 
    $open = '09:00';
    
    Schedule::command('orders:assign-backlog')->dailyAt($open);
    Schedule::command('orders:check-waiting-location')->everyFiveMinutes();
    Schedule::command('orders:check-delayed')->everyMinute();
    Schedule::command('orders:check-novedad-timeout')->everyMinute();
    Schedule::command('shops:check-schedule')->everyMinute();

    Schedule::call(function () {
        app(\App\Services\Assignment\WeightedAssigner::class)->reset();
    })->dailyAt($open);

    // Fase 4: órdenes que esperan en "Nuevo" porque todas las vendedoras estaban en su máximo.
    Schedule::command('orders:assign-waiting')->everyMinute()->withoutOverlapping();
    // Alerta de saturación de las vendedoras respecto de su grupo (spec de la Líder §9)
    Schedule::command('groups:check-saturation')->everyMinute()->withoutOverlapping();
    // Órdenes que esperan agencia porque todas las de su ciudad estaban llenas (tarea 6)
    Schedule::command('orders:assign-agencies')->everyMinute()->withoutOverlapping();
    // Conciliación de pagos digitales: cada mañana, la del día anterior (documento de Fran del 2026-10-06, §12)
    Schedule::command('reconciliation:prepare')->dailyAt('06:00');
    // Meta Ads, solo lectura (documento de Fran del 2026-10-08, Módulo 2): cada 30 minutos (§7), y los días recientes
    // otra vez porque Meta corrige las compras atribuidas (§25)
    Schedule::command('meta:sync')->everyThirtyMinutes()->withoutOverlapping();
    Schedule::command('meta:sync --recheck=7')->dailyAt('04:00');
    Schedule::command('meta:sync --recheck=30')->weeklyOn(0, '04:30');
    // Los trabajos de Meta van por su propia cola: los procesa este worker de corta vida, uno a la vez, y no los
    // workers de siempre (que leen los comprobantes de pago). Termina cuando la cola queda vacía.
    Schedule::command('queue:work --queue=meta --stop-when-empty --tries=1 --timeout=85 --max-time=540')
        ->everyMinute()->withoutOverlapping(15)->runInBackground();

} catch (\Throwable $e) {
    // Fail silently if DB not ready
}
