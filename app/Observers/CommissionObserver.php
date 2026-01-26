<?php

namespace App\Observers;

use App\Models\Commission;
use App\Models\OrderActivityLog;

class CommissionObserver
{
    public function created(Commission $commission): void
    {
        if ($commission->order_id) {
            OrderActivityLog::create([
                'order_id' => $commission->order_id,
                'user_id' => auth()->id(), // null if automated
                'action' => 'commission_generated',
                'description' => "Comisión generada: {$commission->amount_usd} USD para {$commission->role} ({$commission->user->names})",
                'properties' => $commission->toArray()
            ]);
        }
    }
}
