<?php

namespace App\Observers;

use App\Models\OrderProduct;
use App\Models\OrderActivityLog;

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
    }
}
