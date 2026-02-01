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

        try {
            $service = app(\App\Services\Business\BusinessService::class);
            $result = $service->openShop(
                $shopId, 
                $request->boolean('assign_backlog', false),
                Auth::id()
            );

            return response()->json([
                'status'  => true,
                'message' => $result['assigned'] > 0
                    ? "Jornada abierta. Backlog asignado: {$result['assigned']} órdenes."
                    : "Jornada abierta.",
                'data'    => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    public function close(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');
        if (!$shopId) return response()->json(['error' => 'shop_id required'], 400);

        try {
            $service = app(\App\Services\Business\BusinessService::class);
            $result = $service->closeShop($shopId, Auth::id());

            return response()->json([
                'status'  => true,
                'message' => "Jornada cerrada.",
                'data'    => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }
}
