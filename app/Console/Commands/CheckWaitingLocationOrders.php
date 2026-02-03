<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckWaitingLocationOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:check-waiting-location';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notifica si una orden ha estado en "Esperando Ubicacion" por más de 30 minutos.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $status = \App\Models\Status::where('description', 'Esperando Ubicacion')->first();
        if (!$status) return;

        $threshold = now()->subMinutes(30);

        $orders = \App\Models\Order::where('status_id', $status->id)
            ->where('updated_at', '<', $threshold)
            ->with(['agent'])
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No hay órdenes estancadas en "Esperando Ubicacion".');
            return;
        }

        $admins = \App\Models\User::whereHas('role', function($q) {
            $q->whereIn('description', ['Admin', 'Gerente']);
        })->get();

        foreach ($orders as $order) {
            $this->info("Notificando orden #{$order->name}");
            
            // Notificar al vendedor asignado
            if ($order->agent) {
                $order->agent->notify(new \App\Notifications\OrderWaitingLocationNotification($order, "La orden #{$order->name} lleva más de 30 min esperando ubicación. Por favor, contacta al cliente."));
            } else {
                // Si no tiene vendedor (caso raro), notificar a admins
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\OrderWaitingLocationNotification($order, "Orden #{$order->name} (sin vendedor) lleva más de 30 min esperando ubicación."));
                }
            }
        }
    }
}
