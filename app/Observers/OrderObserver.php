<?php
// app/Observers/OrderObserver.php
namespace App\Observers;

use App\Models\Order;
use App\Services\Assignment\AssignOrderService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use App\Constants\OrderStatus;
use App\Models\WhatsappMessage;

class OrderObserver
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    public function created(Order $order): void
    {
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
                $oldStatusDesc = \App\Models\Status::find($oldValue)?->description ?? 'N/A';
                $newStatusDesc = \App\Models\Status::find($newValue)?->description ?? 'N/A';
                $descriptions[] = "Estado cambió de '{$oldStatusDesc}' a '{$newStatusDesc}'";

                // --- Automated WhatsApp Notifications (DESACTIVADO temporalmente por solicitud del cliente) ---
                // $this->handleStatusWhatsApp($order, $newStatusDesc);
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

    /**
     * Send automated messages to the client based on status
     */
    protected function handleStatusWhatsApp(Order $order, string $status)
    {
        $message = null;
        $orderNum = $order->name ?? $order->order_number;
        $shopName = $order->shop?->name ?? 'tu tienda de confianza';

        switch ($status) {
            case OrderStatus::ASIGNADO_VENDEDOR:
                $message = "¡Hola {$order->client->first_name}! 👋 Gracias por elegir a {$shopName}. Te confirmamos que hemos recibido tu orden {$orderNum} y ya está siendo procesada por nuestro equipo. ¡Muy pronto estaremos en contacto contigo! 🛒✨";
                break;
            case OrderStatus::EN_RUTA:
                $message = "¡Buenas noticias! Tu pedido {$orderNum} en {$shopName} ya está en ruta con nuestro repartidor 🏍️. Favor estar atento!";
                break;
            case OrderStatus::ENTREGADO:
                $message = "¡Tu pedido {$orderNum} ha sido entregado con éxito! 🎉 Muchas gracias por confiar en {$shopName}. ¡Que lo disfrutes!";
                break;
            case OrderStatus::PROGRAMADO_OTRO_DIA:
            case OrderStatus::REPROGRAMADO_HOY:
                $date = $order->scheduled_for ? $order->scheduled_for->format('d/m/Y') : 'pronto';
                $message = "¡Hola! Hemos agendado la entrega de tu orden {$orderNum} en {$shopName} para la fecha: {$date}. ¡Nos vemos luego!";
                break;
        }

        if ($message && $order->client && $order->client->phone) {
            $msgRecord = \App\Models\WhatsappMessage::create([
                'order_id' => $order->id,
                'body' => $message,
                'is_from_client' => false,
                'status' => 'sending',
                'sent_at' => now(),
            ]);

            $result = $this->whatsappService->sendMessage($order->client->phone, $message);
            
            if ($result && isset($result['messages'][0]['id'])) {
                $msgRecord->update([
                    'message_id' => $result['messages'][0]['id'],
                    'status' => 'sent'
                ]);
            } else {
                $msgRecord->update(['status' => 'failed']);
            }

            // Sync Frontend UI
            event(new \App\Events\WhatsappMessageReceived($msgRecord));
        }
    }
}

