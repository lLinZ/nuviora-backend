<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $open  = \App\Models\Setting::get('business_open_at', '09:00');
        $close = \App\Models\Setting::get('business_close_at', '18:00');

        $schedule->command('orders:assign-backlog')->dailyAt($open);

        $schedule->command('orders:check-waiting-location')->everyFiveMinutes();
        $schedule->command('orders:check-delayed')->everyMinute();
        $schedule->command('orders:check-novedad-timeout')->everyMinute();


        $schedule->call(function () {
            \App\Models\Setting::set('round_robin_pointer', null);
        })->dailyAt($open);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
