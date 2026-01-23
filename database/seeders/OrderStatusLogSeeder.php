<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Status;
use App\Models\OrderStatusLog;
use Carbon\Carbon;

class OrderStatusLogSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Limpiar logs para evitar duplicados o inconsistencias previas
        OrderStatusLog::truncate();

        $orders = Order::all();
        $statuses = Status::all()->pluck('id', 'description');

        foreach ($orders as $order) {
            $currentStatusId = $order->status_id;
            $currentStatusName = Status::find($currentStatusId)?->description;
            
            if (!$currentStatusName) continue;

            $created = Carbon::parse($order->created_at);
            
            // Paso 1: Todos nacen como "Nuevo"
            $this->createLog($order->id, $statuses['Nuevo'], null, $created);

            // Determinar historial basado en el estado ACTUAL
            $history = [];

            // Si ya avanzó de nuevo...
            if ($currentStatusName !== 'Nuevo') {
                // Casi todo pasa por Llamado 1
                $history[] = 'Llamado 1';

                // Lógica de Venta / Asignación
                if (in_array($currentStatusName, ['Llamado 2', 'Llamado 3', 'Programado para otro dia', 'Asignar a agencia', 'En ruta', 'Entregado', 'Rechazado'])) {
                    if (rand(0, 100) > 30) $history[] = 'Llamado 2';
                }

                // Lógica de Logística
                if (in_array($currentStatusName, ['Asignar a agencia', 'En ruta', 'Entregado', 'Rechazado', 'Reagendado'])) {
                    $history[] = 'Asignar a agencia';
                }

                // Lógica de Ruta
                if (in_array($currentStatusName, ['En ruta', 'Entregado', 'Rechazado'])) {
                    $history[] = 'En ruta';
                }
            }

            // Insertar historial intermedio (solo si NO es el estado actual)
            $simulatedTime = $created->copy();
            foreach ($history as $statusName) {
                if ($statuses[$statusName] != $currentStatusId) {
                    $simulatedTime->addMinutes(rand(60, 300)); // Avanzar tiempo
                    $this->createLog($order->id, $statuses[$statusName], $order->agent_id, $simulatedTime);
                }
            }

            // Paso Final: El estado ACTUAL (si no es Nuevo, que ya se insertó al inicio)
            if ($currentStatusName !== 'Nuevo') {
                $this->createLog($order->id, $currentStatusId, $order->agent_id, $order->updated_at);
            }
        }
    }

    private function createLog($orderId, $statusId, $userId, $date)
    {
        // Check simple para no duplicar el último estado si la lógica falla
        $exists = OrderStatusLog::where('order_id', $orderId)
            ->where('to_status_id', $statusId)
            ->exists();

        if (!$exists && $statusId) {
            OrderStatusLog::create([
                'order_id' => $orderId,
                'from_status_id' => null, // Opcional: podrías calcularlo del anterior
                'to_status_id' => $statusId,
                'user_id' => $userId,
                'created_at' => $date,
                'updated_at' => $date
            ]);
        }
    }
}
