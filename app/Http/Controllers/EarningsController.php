<?php

namespace App\Http\Controllers;

use App\Services\EarningsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EarningsController extends Controller
{
    public function __construct(
        protected EarningsService $service
    ) {}

    /**
     * Admin: resumen de ganancias por rol y usuario.
     * GET /api/earnings/summary?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function summary(Request $request)
    {
        $user = Auth::user();
        $roleDesc = $user->role?->description;

        if (!in_array($roleDesc, ['Admin', 'Gerente', 'Agencia'])) {
            return response()->json([
                'status'  => false,
                'message' => 'No autorizado',
            ], 403);
        }

        $agencyId = $roleDesc === 'Agencia' ? $user->id : null;

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : now()->startOfDay();

        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        $data = $this->service->summary($from, $to, $agencyId);

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }

    /**
     * Cada usuario ve SUS ganancias personales del día.
     * GET /api/earnings/me?date=YYYY-MM-DD
     */
    public function me(Request $request)
    {
        $user = Auth::user();

        $date = $request->query('date');
        if ($date) {
            $from = Carbon::parse($date)->startOfDay();
            $to   = Carbon::parse($date)->endOfDay();
        } else {
            $from = now()->startOfDay();
            $to   = now()->endOfDay();
        }

        $data = $this->service->forUser($user, $from, $to);

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }
}
