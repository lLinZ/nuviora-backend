<?php

// app/Http/Controllers/AssignmentController.php
namespace App\Http\Controllers;

use App\Services\Assignment\AssignOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AssignmentController extends Controller
{
    protected function ensureManager(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }

    public function assignBacklog(Request $request)
    {
        $this->ensureManager();

        $from = $request->filled('from') ? now()->parse($request->from) : now()->yesterday()->endOfDay();
        $to   = $request->filled('to')   ? now()->parse($request->to)   : now();

        $count = app(AssignOrderService::class)->assignBacklog($from, $to);

        return response()->json([
            'status' => true,
            'message' => "Backlog asignado: {$count} órdenes",
            'data'   => ['assigned' => $count],
        ]);
    }
}
