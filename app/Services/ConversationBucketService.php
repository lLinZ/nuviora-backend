<?php

namespace App\Services;

use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Log;

/**
 * ConversationBucketService
 *
 * Única fuente de verdad para calcular y persistir el conversation_bucket.
 *
 * Reglas (de más prioritaria a menos):
 * 1. closed       → conversación tiene status closed/resolved, OR pedido Entregado/Cancelado
 * 2. requires_attention → último mensaje relevante es incoming_message
 * 3. requires_attention → cliente respondió después de una automatización
 * 4. follow_up    → último mensaje relevante es outgoing_agent_message o outgoing_automated_message
 * 5. follow_up    → default
 *
 * CRÍTICO: system_event y outgoing_automated_message NO cuentan como "respuesta"
 * del agente y NO cambian el bucket a follow_up si hay un mensaje del cliente sin responder.
 */
class ConversationBucketService
{
    /**
     * Recalcula y persiste el conversation_bucket para un cliente dado.
     * Llama esto cada vez que se crea un mensaje nuevo.
     *
     * @param int $clientId
     * @return string El bucket resultante
     */
    public static function recalculate(int $clientId): string
    {
        // CRÍTICO: Si no existe conversación abierta, crearla.
        $conv = WhatsappConversation::firstOrCreate(
            ['client_id' => $clientId, 'status' => 'open'],
            ['conversation_bucket' => WhatsappConversation::BUCKET_FOLLOW_UP]
        );

        // Obtener el último mensaje relevante
        $lastRelevant = WhatsappMessage::where('client_id', $clientId)
            ->whereIn('message_type', [
                WhatsappMessage::TYPE_INCOMING,
                WhatsappMessage::TYPE_AGENT,
                WhatsappMessage::TYPE_AUTOMATED,
            ])
            ->latest('sent_at')
            ->first();

        // Si el último mensaje es del cliente, se pierde el override manual
        // (por si el vendedor lo movió a seguimiento pero el cliente volvió a escribir)
        if ($lastRelevant && $lastRelevant->message_type === WhatsappMessage::TYPE_INCOMING) {
            $conv->is_manual_bucket = false;
        }

        $bucket = self::calculateBucket($conv);

        $conv->update([
            'conversation_bucket' => $bucket,
            'last_message_at'     => $lastRelevant?->sent_at ?? now(),
            'is_manual_bucket'     => $conv->is_manual_bucket // Asegurar que se persiste el reset si ocurrió
        ]);

        return $bucket;
    }

    /**
     * Implementación del pseudocódigo del spec.
     *
     * @param WhatsappConversation $conv
     * @return string
     */
    public static function calculateBucket(WhatsappConversation $conv): string
    {
        // 1. Si fue movido MANUALMENTE por la vendedora, se respeta 
        // (A menos que el cliente haya vuelto a escribir, lo cual se maneja en recalculate)
        if ($conv->is_manual_bucket) {
            return $conv->conversation_bucket;
        }

        // 2. Buscar el último mensaje RELEVANTE (excluir system_event)
        $lastRelevant = WhatsappMessage::where('client_id', $conv->client_id)
            ->whereIn('message_type', [
                WhatsappMessage::TYPE_INCOMING,
                WhatsappMessage::TYPE_AGENT,
                WhatsappMessage::TYPE_AUTOMATED,
            ])
            ->latest('sent_at')
            ->first();

        // 3. PRIORIDAD MÁXIMA: Si el último mensaje relevante es del cliente → requiere atención
        // Esto evita que si un pedido se entregó pero el cliente escribió después, el chat se quede en "Cerrados"
        if ($lastRelevant && $lastRelevant->message_type === WhatsappMessage::TYPE_INCOMING) {
            return WhatsappConversation::BUCKET_ATTENTION;
        }

        // 4. Cerrado: estado explícito de la conversación (botón Limpiar/Cerrar)
        if (in_array($conv->status, ['closed', 'resolved'])) {
            return WhatsappConversation::BUCKET_CLOSED;
        }

        // 5. Cerrado: Por estatus de pedido (Entregado, Cancelado o Rechazado)
        $latestOrder = \App\Models\Order::where('client_id', $conv->client_id)
            ->orderBy('created_at', 'desc')
            ->with('status')
            ->first();

        if ($latestOrder && $latestOrder->status) {
            $desc = $latestOrder->status->description;
            if (in_array($desc, [
                \App\Constants\OrderStatus::ENTREGADO, 
                \App\Constants\OrderStatus::CANCELADO,
                \App\Constants\OrderStatus::RECHAZADO
            ])) {
                return WhatsappConversation::BUCKET_CLOSED;
            }
        }

        if (!$lastRelevant) {
            // Sin mensajes → en seguimiento por defecto
            return WhatsappConversation::BUCKET_FOLLOW_UP;
        }

        // 6. Si el último mensaje es automatización, revisar si hay cliente
        //    respondiendo DESPUÉS de una automatización sin atender
        if ($lastRelevant->message_type === WhatsappMessage::TYPE_AUTOMATED) {
            return WhatsappConversation::BUCKET_FOLLOW_UP;
        }

        // 7. Último mensaje es del agente humano → en seguimiento
        if ($lastRelevant->message_type === WhatsappMessage::TYPE_AGENT) {
            return WhatsappConversation::BUCKET_FOLLOW_UP;
        }

        return WhatsappConversation::BUCKET_FOLLOW_UP;
    }

    /**
     * Fuerza el bucket a 'closed'. Llamar cuando se cambia status a
     * Entregado o Cancelado.
     *
     * @param int $clientId
     */
    public static function closeBucket(int $clientId): void
    {
        WhatsappConversation::where('client_id', $clientId)
            ->update([
                'status' => 'closed',
                'conversation_bucket' => WhatsappConversation::BUCKET_CLOSED
            ]);
    }

    /**
     * Prioridad numérica para ordenar en la lista (menor = más urgente).
     */
    public static function bucketPriority(string $bucket): int
    {
        return match ($bucket) {
            WhatsappConversation::BUCKET_ATTENTION => 1,
            WhatsappConversation::BUCKET_FOLLOW_UP => 2,
            WhatsappConversation::BUCKET_CLOSED    => 3,
            default                                => 2,
        };
    }
}
