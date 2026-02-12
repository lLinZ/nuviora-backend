<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\OrderTrackingComprehensiveLog;
use App\Models\Status;
use App\Models\User;

class OrderTrackingComprehensiveController extends Controller
{
    public function index(Request $request)
    {
        $query = OrderTrackingComprehensiveLog::with([
            'order:id,number',
            'fromStatus:id,description',
            'toStatus:id,description',
            'seller:id,name',
            'user:id,name',
            'previousSeller:id,name'
        ]);

        // Filtro por Agente (Vendedora encargada en ese momento)
        if ($request->filled('agent_id')) {
            $query->where('seller_id', $request->agent_id);
        }

        // Filtro por Status (Al que se movió)
        if ($request->filled('status_id')) {
            $query->where('to_status_id', $request->status_id);
        }

        // Filtro por Rango de Fechas
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('updated_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ]);
        }

        $logs = $query->orderBy('updated_at', 'desc')->paginate(50);

        return response()->json([
            'status' => true,
            'data' => $logs
        ]);
    }

    public function getFilters()
    {
        return response()->json([
            'status' => true,
            'agents' => User::whereHas('role', fn($q) => $q->where('description', 'Vendedor'))->select('id', 'name')->get(),
            'statuses' => Status::select('id', 'description')->get(),
        ]);
    }
}
