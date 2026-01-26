<?php

namespace App\Observers;

use App\Models\OrderStatusLog;
use App\Models\OrderActivityLog;
use App\Models\Status;

class OrderStatusLogObserver
{
    public function created(OrderStatusLog $log): void
    {
        $from = Status::find($log->from_status_id)?->description ?? 'Inicio';
        $to = Status::find($log->to_status_id)?->description ?? 'Desconocido';
        
        OrderActivityLog::create([
            'order_id' => $log->order_id,
            'user_id' => $log->user_id ?? auth()->id(),
            'action' => 'status_changed',
            'description' => "Cambió el estado de '{$from}' a '{$to}'",
            'properties' => $log->toArray()
        ]);
    }
}
