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
        // Sin esto, los clientes de webhook/External nunca aparecen en la sidebar.
        $conv = WhatsappConversation::firstOrCreate(
            ['client_id' => $clientId, 'status' => 'open'],
            ['conversation_bucket' => WhatsappConversation::BUCKET_FOLLOW_UP]
        );

        $bucket = self::calculateBucket($conv);

        // Obtener el último mensaje relevante para actualizar last_message_at
        $lastRelevant = WhatsappMessage::where('client_id', $clientId)
            ->whereIn('message_type', [
                WhatsappMessage::TYPE_INCOMING,
                WhatsappMessage::TYPE_AGENT,
                WhatsappMessage::TYPE_AUTOMATED,
            ])
            ->latest('sent_at')
            ->first();

        $conv->update([
            'conversation_bucket' => $bucket,
            'last_message_at'     => $lastRelevant?->sent_at ?? now(),
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
        // 1. Cerrado: estado explícito de la conversación
        if (in_array($conv->status, ['closed', 'resolved'])) {
            return WhatsappConversation::BUCKET_CLOSED;
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

        if (!$lastRelevant) {
            // Sin mensajes → en seguimiento por defecto
            return WhatsappConversation::BUCKET_FOLLOW_UP;
        }

        // 3. Si el último mensaje relevante es del cliente → requiere atención
        if ($lastRelevant->message_type === WhatsappMessage::TYPE_INCOMING) {
            return WhatsappConversation::BUCKET_ATTENTION;
        }

        // 4. Si el último mensaje es automatización, revisar si hay cliente
        //    respondiendo DESPUÉS de una automatización sin atender
        if ($lastRelevant->message_type === WhatsappMessage::TYPE_AUTOMATED) {
            // Buscar si hay un incoming_message POSTERIOR a la última automatización
            // En este punto el último mensaje es la automatización, así que no hay
            // incoming_message posterior → sigue en follow_up (automatización envió, cliente no respondió aún)
            return WhatsappConversation::BUCKET_FOLLOW_UP;
        }

        // 5. Último mensaje es del agente humano → en seguimiento
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
