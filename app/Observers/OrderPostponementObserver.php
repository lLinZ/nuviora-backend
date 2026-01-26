<?php

namespace App\Observers;

use App\Models\OrderPostponement;
use App\Models\OrderActivityLog;

class OrderPostponementObserver
{
    public function created(OrderPostponement $postponement): void
    {
        OrderActivityLog::create([
            'order_id' => $postponement->order_id,
            'user_id' => auth()->id(),
            'action' => 'postponed',
            'description' => "Pospuso la orden para el " . date('d/m/Y H:i', strtotime($postponement->scheduled_for)) . ". Razón: " . ($postponement->reason ?? 'Sin razón especificada'),
            'properties' => $postponement->toArray()
        ]);
    }
}
