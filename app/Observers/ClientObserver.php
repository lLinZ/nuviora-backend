<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\OrderActivityLog;
use App\Models\Order;

class ClientObserver
{
    public function updated(Client $client): void
    {
        $changes = $client->getChanges();
        unset($changes['updated_at']);
        if (empty($changes)) return;

        $descriptions = [];
        $fieldNames = [
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'phone' => 'Teléfono',
            'email' => 'Email',
            'address1' => 'Dirección 1',
            'address2' => 'Dirección 2',
            'province' => 'Provincia',
            'city' => 'Ciudad',
        ];

        foreach ($changes as $key => $newValue) {
            $oldValue = $client->getOriginal($key);
            $fieldName = $fieldNames[$key] ?? $key;
            $descriptions[] = "Actualizó '{$fieldName}' del cliente de '{$oldValue}' a '{$newValue}'";
        }

        // Find all orders for this client and log the activity in each (to be comprehensive)
        $orders = Order::where('client_id', $client->id)->get();
        foreach ($orders as $order) {
            OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => 'client_updated',
                'description' => implode(' | ', $descriptions),
                'properties' => $changes
            ]);
        }
    }
}
