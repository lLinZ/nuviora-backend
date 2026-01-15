<?php
// app/Http/Controllers/BusinessDayController.php
namespace App\Http\Controllers;

use App\Models\BusinessDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BusinessDayController extends Controller
{
    protected function ensureManager(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }

    public function today(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');
        if (!$shopId) return response()->json(['error' => 'shop_id required'], 400);

        $today = now()->toDateString();
        $day = BusinessDay::firstOrCreate(['date' => $today, 'shop_id' => $shopId]);

        // último cierre previo (para que puedas usarlo en backlog/manual)
        $lastClosed = BusinessDay::whereNotNull('close_at')
            ->where('date', '<', $today)
            ->where('shop_id', $shopId)
            ->orderByDesc('date')
            ->value('close_at');

        return response()->json([
            'status' => true,
            'data' => [
                'date'          => $day->date->toDateString(),
                'open_at'       => optional($day->open_at)->toDateTimeString(),
                'close_at'      => optional($day->close_at)->toDateTimeString(),
                'is_open'       => $day->is_open,
                'last_close_at' => $lastClosed ? (\Illuminate\Support\Carbon::parse($lastClosed))->toDateTimeString() : null,
            ]
        ]);
    }

    public function open(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');
        if (!$shopId) return response()->json(['error' => 'shop_id required'], 400);

        $today = now()->toDateString();
        $day = BusinessDay::firstOrCreate(['date' => $today, 'shop_id' => $shopId]);

        if ($day->open_at) {
            return response()->json([
                'status' => false,
                'message' => 'La jornada de esta tienda ya fue abierta.',
            ], 409);
        }

        $day->update([
            'open_at'   => now(),
            'opened_by' => Auth::id(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Jornada abierta.',
            'data'    => [
                'open_at' => $day->open_at->toDateTimeString(),
                'date'    => $day->date->toDateString(),
            ]
        ]);
    }

    public function close(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');
        if (!$shopId) return response()->json(['error' => 'shop_id required'], 400);

        $today = now()->toDateString();
        $day = BusinessDay::where(['date' => $today, 'shop_id' => $shopId])->first();

        if (!$day || !$day->open_at) {
            return response()->json([
                'status' => false,
                'message' => 'No puedes cerrar sin haber abierto la jornada.',
            ], 422);
        }
        if ($day->close_at) {
            return response()->json([
                'status' => false,
                'message' => 'La jornada ya fue cerrada.',
            ], 409);
        }

        $day->update([
            'close_at'  => now(),
            'closed_by' => Auth::id(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Jornada cerrada.',
            'data'    => [
                'close_at' => $day->close_at->toDateTimeString(),
                'date'     => $day->date->toDateString(),
            ]
        ]);
    }
}
