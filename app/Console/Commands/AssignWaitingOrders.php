<?php

namespace App\Console\Commands;

use App\Constants\OrderStatus;
use App\Models\BusinessDay;
use App\Models\Order;
use App\Models\Status;
use App\Models\User;
use App\Services\Assignment\AssignOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4: cuando todas las vendedoras de una tienda están en su máximo, la orden se queda en "Nuevo"
 * sin vendedora (Fran §22). Este comando corre cada minuto y la asigna en cuanto alguna libera cupo.
 */
class AssignWaitingOrders extends Command
{
    protected $signature = 'orders:assign-waiting';
    protected $description = 'Asigna las órdenes en "Nuevo" que esperaban cupo de alguna vendedora';

    public function handle(): int
    {
        // Sin máximos configurados nadie espera por cupo, y todo sigue como siempre.
        if (!User::whereNotNull('max_active_orders')->exists()) {
            return self::SUCCESS;
        }

        $openShopIds = BusinessDay::where('date', now()->toDateString())
            ->whereNotNull('open_at')
            ->whereNull('close_at')
            ->pluck('shop_id');
        $nuevoId = Status::where('description', OrderStatus::NUEVO)->value('id');
        if ($openShopIds->isEmpty() || !$nuevoId) {
            return self::SUCCESS;
        }

        $orders = Order::whereNull('agent_id')
            ->where('status_id', $nuevoId)
            ->whereIn('shop_id', $openShopIds)
            ->where('created_at', '<', now()->subMinute()) // no pisar un alta en curso (tarea 2)
            ->orderBy('id')
            ->limit(50)
            ->get();

        $service = app(AssignOrderService::class);
        $assigned = 0;
        foreach ($orders as $order) {
            try {
                if ($service->assignOne($order)) {
                    $assigned++;
                }
            } catch (\Throwable $e) {
                Log::error('orders:assign-waiting: ' . $e->getMessage(), ['order_id' => $order->id]);
            }
        }

        if ($assigned > 0) {
            Log::info("orders:assign-waiting: {$assigned} órdenes asignadas al liberarse cupo.");
        }

        return self::SUCCESS;
    }
}
