<?php

namespace App\Observers;

use App\Models\OrderPayment;
use App\Models\OrderActivityLog;

class OrderPaymentObserver
{
    public function created(OrderPayment $orderPayment): void
    {
        OrderActivityLog::create([
            'order_id' => $orderPayment->order_id,
            'user_id' => auth()->id(),
            'action' => 'payment_added',
            'description' => "Se añadió un pago de {$orderPayment->amount} {$orderPayment->order->currency} con el método '{$orderPayment->method}'",
            'properties' => $orderPayment->toArray()
        ]);
    }

    public function updated(OrderPayment $orderPayment): void
    {
        $changes = $orderPayment->getChanges();
        unset($changes['updated_at']);
        if (empty($changes)) return;

        $descriptions = [];
        foreach ($changes as $key => $newValue) {
            $oldValue = $orderPayment->getOriginal($key);
            $descriptions[] = "Actualizó '{$key}' de '{$oldValue}' a '{$newValue}' en el pago #{$orderPayment->id}";
        }

        OrderActivityLog::create([
            'order_id' => $orderPayment->order_id,
            'user_id' => auth()->id(),
            'action' => 'payment_updated',
            'description' => implode(' | ', $descriptions),
            'properties' => ['changes' => $changes, 'id' => $orderPayment->id]
        ]);
    }

    public function deleted(OrderPayment $orderPayment): void
    {
        OrderActivityLog::create([
            'order_id' => $orderPayment->order_id,
            'user_id' => auth()->id(),
            'action' => 'payment_removed',
            'description' => "Se eliminó el pago de {$orderPayment->amount} {$orderPayment->order->currency} con el método '{$orderPayment->method}'",
            'properties' => $orderPayment->toArray()
        ]);
    }
}
