<?php

namespace App\Observers;

use App\Models\OrderUpdate;
use App\Models\OrderActivityLog;

class OrderUpdateObserver
{
    public function created(OrderUpdate $orderUpdate): void
    {
        // Don't log if it's an automated status update note (optional, but keep it for now)
        OrderActivityLog::create([
            'order_id' => $orderUpdate->order_id,
            'user_id' => auth()->id(),
            'action' => 'note_added',
            'description' => "Añadió una nota/actualización: " . substr($orderUpdate->message, 0, 100) . (strlen($orderUpdate->message) > 100 ? '...' : ''),
            'properties' => $orderUpdate->toArray()
        ]);
    }
}
