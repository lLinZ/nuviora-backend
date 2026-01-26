<?php

namespace App\Observers;

use App\Models\DelivererStockItem;
use App\Models\OrderActivityLog;
use App\Models\OrderProduct;

class DelivererStockItemObserver
{
    public function updated(DelivererStockItem $item): void
    {
        // When a deliverer marks an item as delivered, it's usually tied to an order implicity or explicitly
        // If your system finds the related order during delivery registration, we can log it.
        // This is complex because DelivererStockItem doesn't have an order_id.
        // But if we are in the context of an order delivery, we might find it.
        
        if ($item->isDirty('qty_delivered')) {
            $diff = $item->qty_delivered - $item->getOriginal('qty_delivered');
            if ($diff > 0) {
                // Try to find the order that was just updated with this delivery
                // This part depends on how you link stock to orders during the 'deliver' process.
            }
        }
    }
}
