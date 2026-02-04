<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckDelayedOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:check-delayed';

    protected $description = 'Chequea órdenes que hayan excedido 45 min en ruta/asignación y notifica a la agencia';

    public function handle()
    {
        $limitTime = now()->subMinutes(45);
        
        $statusEnRuta = \App\Models\Status::where('description', 'En ruta')->first();
        $statusAsignar = \App\Models\Status::where('description', 'Asignar a agencia')->first();

        if (!$statusEnRuta && !$statusAsignar) return;

        $targetStatuses = array_filter([$statusEnRuta?->id, $statusAsignar?->id]);

        $orders = \App\Models\Order::whereIn('status_id', $targetStatuses)
            ->whereNotNull('received_at')
            ->where('received_at', '<=', $limitTime)
            ->whereNull('delayed_notification_sent_at') // Evitar spam
            ->get();

        foreach ($orders as $order) {
            // Notificar a la agencia asignada
            if ($order->agency) {
                $order->agency->notify(new \App\Notifications\OrderDelayedNotification($order));
            }

            // Opcional: Notificar a Admins también
            /*
            $admins = \App\Models\User::whereHas('role', fn($q) => $q->where('description', 'Admin'))->get();
            foreach ($admins as $admin) {
                $admin->notify(new \App\Notifications\OrderDelayedNotification($order));
            }
            */
            
            // Marcar como notificado
            $order->delayed_notification_sent_at = now();
            $order->save();

            $this->info("Orden #{$order->name} notificada como retrasada.");
        }
    }
}
