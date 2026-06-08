<?php

namespace App\Http\Middleware;

use App\Services\AgencyChatGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea a las agencias con demasiados chats internos sin responder.
 *
 * Devuelve 423 (Locked) en todo el sistema EXCEPTO las rutas que la agencia
 * necesita para desbloquearse: el propio chat interno, la validación de sesión
 * y el logout. El frontend interpreta `agency_chat_gate` para mostrar el modal.
 */
class EnforceAgencyChatGate
{
    /** Rutas que la agencia SIEMPRE puede usar (para poder desbloquearse). */
    private const EXEMPT = [
        'api/internal-chat/*',
        'api/user/data',
        'api/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$request->is(...self::EXEMPT) && AgencyChatGate::isAgency($user)) {
            $pending = AgencyChatGate::pendingCount($user);

            if ($pending >= AgencyChatGate::threshold()) {
                return response()->json([
                    'message'          => 'Tienes mensajes del chat interno sin responder. Respóndelos para seguir usando el sistema.',
                    'agency_chat_gate' => true,
                    'pending_count'    => $pending,
                    'threshold'        => AgencyChatGate::threshold(),
                ], 423);
            }
        }

        return $next($request);
    }
}
