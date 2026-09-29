<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderActivityLog;
use App\Services\Inventory\OrderStock;

class OrderProductObserver
{
    public function created(OrderProduct $orderProduct): void
    {
        $type = $orderProduct->is_upsell ? 'Upsell' : 'Producto';
        OrderActivityLog::create([
            'order_id' => $orderProduct->order_id,
            'user_id' => auth()->id(),
            'action' => 'product_added',
            'description' => "Añadió {$type}: {$orderProduct->quantity}x {$orderProduct->title} a un precio de {$orderProduct->price}",
            'properties' => $orderProduct->toArray()
        ]);
        $this->syncStock($orderProduct);
    }

    public function updated(OrderProduct $orderProduct): void
    {
        if ($orderProduct->wasChanged(['quantity', 'product_id', 'variant_id', 'size'])) {
            $this->syncStock($orderProduct);
        }
    }

    public function deleted(OrderProduct $orderProduct): void
    {
        $type = $orderProduct->is_upsell ? 'Upsell' : 'Producto';
        OrderActivityLog::create([
            'order_id' => $orderProduct->order_id,
            'user_id' => auth()->id(),
            'action' => 'product_removed',
            'description' => "Eliminó {$type}: {$orderProduct->title}",
            'properties' => $orderProduct->toArray()
        ]);
        $this->syncStock($orderProduct);
    }

    /** Si la orden ya tenía su stock descontado, un producto agregado, quitado o cambiado se ajusta en el almacén (tarea 3a). */
    private function syncStock(OrderProduct $orderProduct): void
    {
        try {
            $order = Order::find($orderProduct->order_id);
            if ($order) {
                app(OrderStock::class)->sync($order);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
