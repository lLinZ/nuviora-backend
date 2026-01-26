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
        $day = null;
        
        if ($shopId) {
            $day = \App\Models\BusinessDay::where('date', $today)->where('shop_id', $shopId)->first();
        }

        return response()->json([
            'status' => true,
            'data' => [
                'is_open'      => $day ? $day->is_open : (bool) Setting::get('business_is_open', false),
                'open_at'      => $day ? $day->open_at?->toDateTimeString() : Setting::get('business_open_at', null),
                'close_at'     => $day ? $day->close_at?->toDateTimeString() : Setting::get('business_close_at', null),
                'open_dt'      => $day ? $day->open_at?->toDateTimeString() : Setting::get('business_open_dt', null),
                'close_dt'     => $day ? $day->close_at?->toDateTimeString() : Setting::get('business_close_dt', null),
                'last_open_dt' => $day ? $day->open_at?->toDateTimeString() : Setting::get('business_last_open_dt', null),
                'last_close_dt' => $day ? $day->close_at?->toDateTimeString() : Setting::get('business_last_close_dt', null),
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

        // 1. Update BusinessDay model (used by AssignOrderService)
        $day = \App\Models\BusinessDay::firstOrCreate(['date' => $today, 'shop_id' => $shopId]);
        if (!$day->open_at) {
            $day->update([
                'open_at'   => now(),
                'opened_by' => Auth::id(),
            ]);
        }

        // 2. Legacy Settings (Global)
        Setting::set('business_is_open', true);
        Setting::set('business_open_dt', $now);
        Setting::set('business_last_open_dt', $now);
        Setting::set('round_robin_pointer', null);

        // (Opcional) Asignar backlog en este momento si se pide
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
        if ($day && !$day->close_at) {
            $day->update([
                'close_at'  => now(),
                'closed_by' => Auth::id(),
            ]);
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
                'is_open'  => false
            ]
        ]);
    }
}
