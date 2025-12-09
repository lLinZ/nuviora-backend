<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Setting;
use App\Services\Assignment\AssignOrderService;

class BusinessController extends Controller
{
    protected function ensureManager(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }

    public function status()
    {
        $this->ensureManager();

        return response()->json([
            'status' => true,
            'data' => [
                'is_open'      => (bool) Setting::get('business_is_open', false),
                'open_dt'      => Setting::get('business_open_dt', null),
                'close_dt'     => Setting::get('business_close_dt', null),
                'last_open_dt' => Setting::get('business_last_open_dt', null),
                'last_close_dt' => Setting::get('business_last_close_dt', null),
            ]
        ]);
    }

    public function open(Request $request)
    {
        $this->ensureManager();

        $now = now()->toDateTimeString();

        // Marcamos apertura
        Setting::set('business_is_open', true);
        Setting::set('business_open_dt', $now);
        Setting::set('business_last_open_dt', $now);

        // Opcional: reset del round-robin
        Setting::set('round_robin_pointer', null);

        // (Opcional) Asignar backlog en este momento si se pide
        // from = último cierre registrado o (ayer 23:59) si no existe
        $assigned = 0;
        if ($request->boolean('assign_backlog', false)) {
            $from = Setting::get('business_last_close_dt', now()->yesterday()->endOfDay()->toDateTimeString());
            $to   = now();
            $assigned = app(AssignOrderService::class)->assignBacklog(now()->parse($from), $to);
        }

        return response()->json([
            'status'  => true,
            'message' => $assigned
                ? "Jornada abierta. Backlog asignado: {$assigned} órdenes."
                : "Jornada abierta.",
            'data'    => [
                'open_dt'   => $now,
                'assigned'  => $assigned,
            ]
        ]);
    }

    public function close()
    {
        $this->ensureManager();

        $now = now()->toDateTimeString();

        // Marcamos cierre
        Setting::set('business_is_open', false);
        Setting::set('business_close_dt', $now);
        Setting::set('business_last_close_dt', $now);

        return response()->json([
            'status'  => true,
            'message' => "Jornada cerrada.",
            'data'    => ['close_dt' => $now]
        ]);
    }
}
