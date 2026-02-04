<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\Status;
use App\Notifications\OrderNovedadTimeoutNotification;

class CheckNovedadTimeout extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:check-novedad-timeout';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica novedades con más de 10 minutos y notifica al vendedor.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limitTime = now()->subMinutes(10);
        $novedadStatus = Status::where('description', 'Novedades')->first();

        if (!$novedadStatus) {
            $this->error('Estado "Novedades" no encontrado.');
            return;
        }

        $orders = Order::where('status_id', $novedadStatus->id)
            ->where('updated_at', '<=', $limitTime)
            ->where(function ($query) {
                $query->whereNull('delayed_notification_sent_at')
                      ->orWhereColumn('delayed_notification_sent_at', '<', 'updated_at');
            })
            ->get();

        foreach ($orders as $order) {
            if ($order->agent) {
                // Determine if we should really notify based on some other criteria?
                // For now, simple timeout logic.
                
                $order->agent->notify(new OrderNovedadTimeoutNotification($order));
                
                // Update flag to prevent loop
                // Since this order is in "Novedades", setting this matches the current status state.
                $order->delayed_notification_sent_at = now();
                $order->timestamps = false; // Prevent updated_at from changing to not reset the timer
                $order->save();

                $this->info("Notificación enviada para orden #{$order->name} al vendedor {$order->agent->names}");
            }
        }

        $this->info('Chequeo de novedades finalizado.');
    }
}
