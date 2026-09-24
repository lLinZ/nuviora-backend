<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🔒 Restringe una ruta a ciertos roles (se compara con role->description, sin distinguir mayúsculas).
 * Uso en rutas: ->middleware('role:Admin,Gerente,Master')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = strtolower((string) $request->user()?->role?->description);
        $allowed = array_map('strtolower', $roles);

        if ($role === '' || !in_array($role, $allowed, true)) {
            return response()->json([
                'status'  => false,
                'message' => 'No tienes permisos para realizar esta acción',
            ], 403);
        }

        return $next($request);
    }
}
