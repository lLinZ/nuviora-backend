<?php

namespace App\Services;

use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Log;

/**
 * ConversationBucketService
 *
 * Única fuente de verdad para calcular y persistir el conversation_bucket.
 * Basado en la columna is_from_client (ya que message_type no existe en DB).
 */
class ConversationBucketService
{
    /**
     * Recalcula y persiste el conversation_bucket para un cliente dado.
     */
    public static function recalculate(int $clientId): string
    {
        $conv = WhatsappConversation::firstOrCreate(
            ['client_id' => $clientId, 'status' => 'open'],
            ['conversation_bucket' => WhatsappConversation::BUCKET_FOLLOW_UP]
        );

        // 1. Obtener el último mensaje
        $lastMessage = WhatsappMessage::where('client_id', $clientId)
            ->latest('id')
            ->first();

        // 2. Si hay interaccion (cliente o vendedora), se rompe el bloqueo manual y recalcula
        if ($lastMessage) {
            $conv->is_manual_bucket = false;
            $bucket = self::calculateBucket($conv, $lastMessage);
        } else {
            // Si no hay mensajes, calculamos normal según el estado actual
            $bucket = self::calculateBucket($conv, $lastMessage);
        }

        $conv->update([
            'conversation_bucket' => $bucket,
            'last_message_at'     => $lastMessage?->sent_at ?? now(),
            'is_manual_bucket'    => $conv->is_manual_bucket
        ]);

        return $bucket;
    }

    /**
     * Lógica central de cálculo.
     */
    public static function calculateBucket(WhatsappConversation $conv, ?WhatsappMessage $lastMessage): string
    {
        // 1. Si es manual y no ha escrito el cliente después (manejado en recalculate), respetamos
        if ($conv->is_manual_bucket) {
            return $conv->conversation_bucket;
        }

        // 2. Prioridad MÁXIMA: Si el último mensaje es del cliente -> ATENCIÓN
        // Esto rompe cualquier estado "Cerrado" anterior.
        if ($lastMessage && $lastMessage->is_from_client) {
            return WhatsappConversation::BUCKET_ATTENTION;
        }

        // 3. Prioridad MEDIA: Cerrado por estatus de pedido terminal
        // Solo si el cliente NO ha escrito recientemente (verificado arriba).
        $latestOrder = \App\Models\Order::where('client_id', $conv->client_id)
            ->orderBy('created_at', 'desc')
            ->with('status')
            ->first();

        if ($latestOrder && $latestOrder->status) {
            $desc = $latestOrder->status->description;
            if (in_array($desc, ['Entregado', 'Cancelado', 'Rechazado'])) {
                return WhatsappConversation::BUCKET_CLOSED;
            }
        }

        // 4. Prioridad BAJA: Si la última interacción fue de la vendedora -> SEGUIMIENTO
        if ($lastMessage && !$lastMessage->is_from_client) {
            return WhatsappConversation::BUCKET_FOLLOW_UP;
        }

        return WhatsappConversation::BUCKET_FOLLOW_UP;
    }

    public static function closeBucket(int $clientId): void
    {
        WhatsappConversation::where('client_id', $clientId)
            ->update([
                'status' => 'closed',
                'conversation_bucket' => WhatsappConversation::BUCKET_CLOSED
            ]);
    }
}
