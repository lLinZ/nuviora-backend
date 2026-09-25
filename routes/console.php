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

} catch (\Throwable $e) {
    // Fail silently if DB not ready
}
