<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommissionController extends Controller
{
    public function myToday()
    {
        $today = now()->toDateString();
        $rows = Commission::where('user_id', Auth::id())->where('date', $today)->get();
        return response()->json([
            'status' => true,
            'data' => [
                'total_usd' => $rows->sum('amount_usd'),
                'items' => $rows,
            ]
        ]);
    }

    public function adminSummary(Request $r)
    {
        $this->authorizeAdmin();
        $r->validate(['from' => 'required|date', 'to' => 'required|date']);
        $rows = Commission::with('user')->whereBetween('date', [$r->from, $r->to])->get();

        $byRole = $rows->groupBy('role')->map(fn($g) => [
            'count' => $g->count(),
            'total_usd' => $g->sum('amount_usd'),
            'users' => $g->groupBy('user_id')->map(fn($u) => [
                'user' => $u->first()->user?->only(['id', 'names', 'surnames', 'email']),
                'total_usd' => $u->sum('amount_usd'),
                'orders' => $u->pluck('order_id')->unique()->values(),
            ])->values(),
        ]);

        return response()->json(['status' => true, 'data' => $byRole]);
    }

    protected function authorizeAdmin()
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin'])) abort(403, 'No autorizado');
    }
}
