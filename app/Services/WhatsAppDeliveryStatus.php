<?php

namespace App\Services;

use App\Events\WhatsappMessageStatus;
use App\Jobs\ApplyWhatsappStatus;
use App\Models\WhatsappMessage;

/**
 * Lo que Meta contesta de cada mensaje que sale (15/10/2026). Meta acepta el envío y después avisa por el webhook
 * si se entregó, si el cliente lo leyó o si falló, con el código del error (p. ej. 131042, pagos pendientes).
 * Antes el webhook no lo leía y el chat mostraba el mensaje como enviado aunque al cliente no le llegara nada.
 */
class WhatsAppDeliveryStatus
{
    /** Un estado que llega tarde no hace retroceder al mensaje: Meta no garantiza el orden de los avisos. */
    private const RANK = ['sending' => 0, 'sent' => 1, 'failed' => 2, 'delivered' => 3, 'read' => 4];

    private const LOG_MARKER = 'WhatsApp Webhook Payload Received: ';

    /** Los estados que trae un payload del webhook, de todas sus entradas y cambios. */
    public static function fromPayload(array $payload): array
    {
        $statuses = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    if (is_array($status)) {
                        $statuses[] = $status;
                    }
                }
            }
        }

        return $statuses;
    }

    /** El payload de una línea del log del webhook, para releer los avisos que llegaron antes de este cambio. */
    public static function payloadFromLogLine(string $line): ?array
    {
        $at = strpos($line, self::LOG_MARKER);
        $json = $at === false ? '' : substr($line, $at + strlen(self::LOG_MARKER));
        $end = strrpos($json, '}');
        $payload = $end === false ? null : json_decode(substr($json, 0, $end + 1), true);

        return is_array($payload) ? $payload : null;
    }

    /** Lo que cambia un aviso de Meta en un mensaje que está en $current, o null si no lo hace avanzar. */
    public static function change(?string $current, array $status): ?array
    {
        $new = $status['status'] ?? null;
        if (!isset(self::RANK[$new]) || self::RANK[$new] <= (self::RANK[$current] ?? 0)) {
            return null;
        }

        $fields = ['status' => $new, 'error_code' => null, 'error_reason' => null];
        if ($new === 'failed') {
            $error = $status['errors'][0] ?? [];
            $fields['error_code'] = isset($error['code']) ? (int) $error['code'] : null;
            $fields['error_reason'] = mb_substr(WhatsAppService::explainError($error, 'Meta no dio el motivo.'), 0, 500);
        }

        return $fields;
    }

    /** Aplica un aviso de Meta a su mensaje. Devuelve el mensaje si cambió; null si no es nuestro o no cambia nada. */
    public static function apply(array $status): ?WhatsappMessage
    {
        $messageId = $status['id'] ?? null;
        $message = $messageId ? WhatsappMessage::where('message_id', $messageId)->where('is_from_client', false)->first() : null;
        $fields = $message ? self::change($message->status, $status) : null;
        if (!$fields) {
            return null;
        }

        // Solo si sigue en un estado anterior: "entregado" y "leído" pueden llegar a la vez en dos solicitudes
        $before = array_keys(array_filter(self::RANK, fn ($rank) => $rank < self::RANK[$fields['status']]));
        $updated = WhatsappMessage::whereKey($message->id)->whereIn('status', $before)->update($fields + ['updated_at' => now()]);

        return $updated ? $message->forceFill($fields)->syncOriginal() : null;
    }

    /**
     * Aplica los estados de un payload. Desde el webhook ($live) avisa a los chats abiertos y, si un rechazo llega antes
     * de que el envío guarde su message_id, lo reintenta una vez. Devuelve cuántos mensajes cambiaron.
     */
    public static function handlePayload(array $payload, bool $live = true): int
    {
        $changed = 0;
        foreach (self::fromPayload($payload) as $status) {
            $message = self::apply($status);
            if (!$message) {
                if ($live && ($status['status'] ?? null) === 'failed'
                    && !WhatsappMessage::where('message_id', $status['id'] ?? '')->exists()) {
                    ApplyWhatsappStatus::dispatch($status)->delay(now()->addSeconds(15));
                }
                continue;
            }
            $changed++;
            if ($live) {
                event(new WhatsappMessageStatus($message));
            }
        }

        return $changed;
    }
}
