<?php

namespace App\Observers;

use App\Models\Order;

class OrderTrackingObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        \App\Models\OrderTrackingComprehensiveLog::log($order, $order->status_id);
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        if ($order->wasChanged('status_id') || $order->wasChanged('agent_id')) {
            \App\Models\OrderTrackingComprehensiveLog::log($order, $order->status_id);
        }
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        //
    }
}
