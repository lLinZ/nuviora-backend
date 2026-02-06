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
        Setting::set('round_robin_pointer', null);
    })->dailyAt($open);

} catch (\Throwable $e) {
    // Fail silently if DB not ready
}
