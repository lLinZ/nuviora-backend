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

    public function status(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');
        
        $today = now()->toDateString();
        
        if ($shopId) {
            $day = \App\Models\BusinessDay::where('date', $today)->where('shop_id', $shopId)->first();
            
            return response()->json([
                'status' => true,
                'data' => [
                    'is_open'       => $day ? $day->is_open : false,
                    'open_at'       => $day ? optional($day->open_at)->toDateTimeString() : null,
                    'close_at'      => $day ? optional($day->close_at)->toDateTimeString() : null,
                    'last_open_dt'  => $day ? optional($day->open_at)->toDateTimeString() : null,
                    'last_close_dt' => $day ? optional($day->close_at)->toDateTimeString() : null,
                    'date'          => $today
                ]
            ]);
        }

        // Behavior for calls without shop_id (Legacy / Global fallback)
        return response()->json([
            'status' => true,
            'data' => [
                'is_open'      => (bool) Setting::get('business_is_open', false),
                'open_at'      => Setting::get('business_open_at', null),
                'close_at'     => Setting::get('business_close_at', null),
                'date'         => $today
            ]
        ]);
    }

    public function open(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');
        if (!$shopId) return response()->json(['error' => 'shop_id required'], 400);

        $now = now()->toDateTimeString();
        $today = now()->toDateString();

        // 1. Update BusinessDay model
        try {
            $day = \App\Models\BusinessDay::firstOrCreate(['date' => $today, 'shop_id' => $shopId]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle race condition for unique constraint
            $day = \App\Models\BusinessDay::where('date', $today)->where('shop_id', $shopId)->first();
            if (!$day) throw $e;
        }

        if (!$day->open_at) {
            $day->update([
                'open_at'   => now(),
                'opened_by' => Auth::id(),
            ]);
        } else {
             return response()->json([
                'status' => false,
                'message' => 'La jornada de esta tienda ya fue abierta.',
            ], 409);
        }

        // 2. Legacy Settings (Global) - Update to start pointing round robin correctly/globally if needed
        Setting::set('business_is_open', true);
        Setting::set('business_open_dt', $now);
        Setting::set('business_last_open_dt', $now);
        Setting::set('round_robin_pointer', null);

        // (Opcional) Asignar backlog
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
                'open_at'   => $day->open_at->toDateTimeString(),
                'assigned'  => $assigned,
                'is_open'   => true
            ]
        ]);
    }

    public function close(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');
        if (!$shopId) return response()->json(['error' => 'shop_id required'], 400);

        $now = now()->toDateTimeString();
        $today = now()->toDateString();

        // 1. Update BusinessDay model
        $day = \App\Models\BusinessDay::where('date', $today)->where('shop_id', $shopId)->first();
        
        if (!$day || !$day->open_at) {
             return response()->json([
                'status' => false,
                'message' => 'No puedes cerrar sin haber abierto la jornada.',
            ], 422);
        }

        if ($day && !$day->close_at) {
            $day->update([
                'close_at'  => now(),
                'closed_by' => Auth::id(),
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'La jornada ya fue cerrada.',
            ], 409);
        }

        // 2. Legacy Settings (Global)
        Setting::set('business_is_open', false);
        Setting::set('business_close_dt', $now);
        Setting::set('business_last_close_dt', $now);

        return response()->json([
            'status'  => true,
            'message' => "Jornada cerrada.",
            'data'    => [
                'close_dt' => $now,
                'close_at' => $day->close_at->toDateTimeString(),
                'is_open'  => false
            ]
        ]);
    }
}
