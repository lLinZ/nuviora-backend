<?php

namespace App\Http\Controllers;

use App\Services\EarningsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        // La agencia ve su liquidación (lo cobrado, los vueltos y el saldo), pero no sus carreras ni lo que se le paga
        // por ellas: eso solo lo ve el Admin, para que no haya confusiones (Fran, 2026-10-06)
        if ($agencyId) {
            $data = Arr::only($data, ['rates', 'from', 'to', 'orders_with_change', 'agency_settlement']);
            $data['agency_settlement'] = $data['agency_settlement']->map(fn ($a) => EarningsService::withoutTrips($a))->values();
        }

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

        // Las ganancias de una agencia son sus carreras: solo las ve el Admin (Fran, 2026-10-06)
        if ($user->role?->description === 'Agencia') {
            return response()->json([
                'status'  => false,
                'message' => 'No autorizado',
            ], 403);
        }

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
