<?php

namespace App\Observers;

use App\Models\InventoryMovement;
use App\Models\OrderActivityLog;
use App\Models\Order;

class InventoryMovementObserver
{
    public function created(InventoryMovement $movement): void
    {
        // Only log if it's related to an Order
        if ($movement->reference_type === Order::class || $movement->reference_type === 'App\Models\Order') {
            $orderId = $movement->reference_id;
            
            $actionStr = '';
            switch ($movement->movement_type) {
                case 'in': $actionStr = 'Devolución de stock'; break;
                case 'out': $actionStr = 'Salida de stock (Venta)'; break;
                case 'adjustment': $actionStr = 'Ajuste de stock'; break;
                case 'transfer': $actionStr = 'Transferencia de stock'; break;
            }

            OrderActivityLog::create([
                'order_id' => $orderId,
                'user_id' => $movement->user_id ?? auth()->id(),
                'action' => 'stock_movement',
                'description' => "Movimiento de inventario: {$actionStr} | Producto: {$movement->product->title} | Cantidad: {$movement->quantity}",
                'properties' => $movement->toArray()
            ]);
        }
    }
}
