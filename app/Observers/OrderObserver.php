<?php
// app/Observers/OrderObserver.php
namespace App\Observers;

use App\Models\Order;
use App\Services\Assignment\AssignOrderService;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    public function created(Order $order): void
    {
        // Log status change (legacy)
        \App\Models\OrderStatusLog::create([
            'order_id' => $order->id,
            'from_status_id' => null,
            'to_status_id' => $order->status_id,
            'user_id' => auth()->id(),
        ]);

        // Log creation
        \App\Models\OrderActivityLog::create([
            'order_id' => $order->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'description' => 'Orden creada/importada.',
        ]);

        try {
            app(AssignOrderService::class)->assignOne($order);
        } catch (\Throwable $e) {
            Log::error('Auto-assign failed: ' . $e->getMessage(), ['order_id' => $order->id]);
        }
    }

    public function updated(Order $order): void
    {
        $changes = $order->getChanges();
        unset($changes['updated_at']);

        if (empty($changes)) return;

        $descriptions = [];
        $properties = [];

        $fieldNames = [
            'status_id'           => 'Estado',
            'location'            => 'Ubicación',
            'agent_id'            => 'Vendedor',
            'agency_id'           => 'Agencia',
            'deliverer_id'        => 'Repartidor',
            'city_id'             => 'Ciudad',
            'current_total_price' => 'Total',
            'novedad_type'        => 'Tipo de Novedad',
            'novedad_description' => 'Descripción de Novedad',
            'novedad_resolution'  => 'Resolución de Novedad',
            'payment_receipt'     => 'Comprobante de Pago',
            'ves_price'           => 'Monto en VES',
            'cash_received'       => 'Efectivo Recibido',
            'change_amount'       => 'Vuelto',
            'reminder_at'         => 'Recordatorio',
            'was_shipped'         => 'Marcado como enviado',
        ];

        foreach ($changes as $key => $newValue) {
            $oldValue = $order->getOriginal($key);
            $fieldName = $fieldNames[$key] ?? $key;

            if ($key === 'status_id') {
                // Log status change (legacy)
                \App\Models\OrderStatusLog::create([
                    'order_id' => $order->id,
                    'from_status_id' => $oldValue,
                    'to_status_id' => $newValue,
                    'user_id' => auth()->id(),
                ]);
                // We don't add to $descriptions here because OrderStatusLogObserver will handle the activity log entry
            } elseif ($key === 'agent_id') {
                $oldVal = \App\Models\User::find($oldValue)?->names ?? 'Nadie';
                $newVal = \App\Models\User::find($newValue)?->names ?? 'Nadie';
                $descriptions[] = "Cambió el vendedor de '{$oldVal}' a '{$newVal}'";
            } elseif ($key === 'agency_id') {
                $oldVal = \App\Models\User::find($oldValue)?->names ?? 'Ninguna';
                $newVal = \App\Models\User::find($newValue)?->names ?? 'Ninguna';
                $descriptions[] = "Cambió la agencia de '{$oldVal}' a '{$newVal}'";
            } elseif ($key === 'deliverer_id') {
                $oldVal = \App\Models\User::find($oldValue)?->names ?? 'Nadie';
                $newVal = \App\Models\User::find($newValue)?->names ?? 'Nadie';
                $descriptions[] = "Cambió el repartidor de '{$oldVal}' a '{$newVal}'";
            } elseif ($key === 'city_id') {
                $oldVal = \App\Models\City::find($oldValue)?->name ?? 'Ninguna';
                $newVal = \App\Models\City::find($newValue)?->name ?? 'Ninguna';
                $descriptions[] = "Cambió la ciudad de '{$oldVal}' a '{$newVal}'";
            } else {
                $descriptions[] = "Actualizó '{$fieldName}' de '{$oldValue}' a '{$newValue}'";
            }

            $properties[$key] = [
                'old' => $oldValue,
                'new' => $newValue
            ];
        }

        if (!empty($descriptions)) {
            \App\Models\OrderActivityLog::create([
                'order_id'    => $order->id,
                'user_id'     => auth()->id(),
                'action'      => 'updated',
                'description' => implode(' | ', $descriptions),
                'properties'  => $properties,
            ]);
        }
    }
}
