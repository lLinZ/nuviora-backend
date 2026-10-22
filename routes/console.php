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
    // Órdenes que esperaban que su ciudad volviera a trabajar: apagada o fuera de horario (Fran, 2026-10-17)
    Schedule::command('agencies:release-waiting')->everyMinute()->withoutOverlapping();
    // Logística propia (Fran, 21/10): sin operador y órdenes que esperan ventana. Una pasada por minuto hasta que el
    // proceso de supervisor (logistics:tick --loop) corra las rondas de 90 s (paso S4 del plan)
    Schedule::command('logistics:tick')->everyMinute()->withoutOverlapping();
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
    // Tarifas de delivery (Módulo 3 de Fran): las rutas de Google Routes y los enlaces de Maps van por su propia cola,
    // con un worker de corta vida como el de Meta. Cada hora se reintenta lo que Google no pudo responder (§42).
    Schedule::command('queue:work --queue=delivery --stop-when-empty --tries=3 --timeout=40 --max-time=300')
        ->everyMinute()->withoutOverlapping(10)->runInBackground();
    Schedule::command('delivery:retry')->hourly()->withoutOverlapping();
    // Cierres semanales de las agencias (§20): al terminar la semana se abre el de cada agencia con carreras. Cada hora,
    // por si el servidor estuvo apagado el lunes. El archivo se procesa en su propia cola (la IA puede tardar).
    Schedule::command('delivery:closures')->hourly()->withoutOverlapping();
    Schedule::command('queue:work --queue=closures --stop-when-empty --tries=1 --timeout=420 --max-time=500')
        ->everyMinute()->withoutOverlapping(15)->runInBackground();
    // Finanzas (Módulo 4 de Fran): la economía de cada orden se recalcula al minuto de cualquier cambio, y cada noche
    // se marcan todas por si algún cambio no pasó por los observers
    Schedule::command('finance:rebuild')->everyMinute()->withoutOverlapping(10)->runInBackground();
    Schedule::command('finance:rebuild --mark-all')->dailyAt('03:30');
    // Libro de lotes (entrega 2): reproduce los movimientos de inventario nuevos con FIFO por lote
    Schedule::command('finance:lots')->everyMinute()->withoutOverlapping(10)->runInBackground();
    // Caja (entrega 3): los movimientos automáticos se cuadran solos con su origen; cada noche se revisan todos
    Schedule::command('finance:cash --resync')->dailyAt('03:45')->withoutOverlapping(60);
    // CPA financiero con la ventana madura, los miércoles después del recordatorio de cierres de las 11:00 (§61)
    Schedule::command('finance:cpa')->wednesdays()->at('12:00')->withoutOverlapping();
    // Reportes (entrega 4): los automáticos (diario D-3, semanal, mensual, §53 a §55) se generan solos cuando les toca;
    // cada noche, después de recalcular las órdenes, los recientes sacan versión nueva si cambiaron sus cifras (§20, §21)
    Schedule::command('finance:reports')->everyTenMinutes()->withoutOverlapping(30)->runInBackground();
    Schedule::command('finance:reports --refresh')->dailyAt('04:15')->withoutOverlapping(90);
    // Módulo 5 (entrega 4): autoevaluación de los planes que terminaron su horizonte (§98) y, si el Admin lo enciende, el
    // plan automático la noche antes de un día de ruta (§66, §107; viene apagado)
    Schedule::command('replenishment:evaluate')->dailyAt('05:00')->withoutOverlapping(60);
    Schedule::command('replenishment:auto-plan')->dailyAt('21:00')->withoutOverlapping(30);

} catch (\Throwable $e) {
    // Fail silently if DB not ready
}
