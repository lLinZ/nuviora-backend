<?php

namespace App\Services;

use App\Models\InternalConversation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Reglas del "candado" del chat interno para agencias.
 *
 * Una agencia con demasiadas conversaciones esperando su respuesta queda
 * bloqueada del resto del sistema hasta que las conteste. "Esperando respuesta"
 * = el ÚLTIMO mensaje del hilo NO es suyo (lo escribió la vendedora o un admin).
 */
class AgencyChatGate
{
    /** A partir de esta cantidad de hilos pendientes, la agencia queda bloqueada. */
    public const THRESHOLD = 5;

    public static function threshold(): int
    {
        return (int) config('services.agency_chat_gate.threshold', self::THRESHOLD);
    }

    public static function isAgency(?User $user): bool
    {
        if (!$user) return false;
        if (!$user->relationLoaded('role')) $user->load('role');

        return strtolower(trim($user->role->description ?? '')) === 'agencia';
    }

    /**
     * Conversaciones de la agencia cuyo último mensaje NO es suyo.
     *
     * @return Collection<int, InternalConversation>
     */
    public static function pendingConversations(User $user, array $with = []): Collection
    {
        return InternalConversation::query()
            ->whereHas('order', fn ($q) => $q->where('agency_id', $user->id))
            ->whereHas('messages')
            ->with(array_merge(['lastMessage'], $with))
            ->get()
            ->filter(fn (InternalConversation $c) =>
                $c->lastMessage && (int) $c->lastMessage->sender_id !== (int) $user->id)
            ->values();
    }

    public static function pendingCount(User $user): int
    {
        return self::pendingConversations($user)->count();
    }

    public static function isBlocked(?User $user): bool
    {
        return self::isAgency($user) && self::pendingCount($user) >= self::threshold();
    }
}
