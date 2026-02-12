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
            'order' => fn($q) => $q->select('id', 'order_number as number'),
            'fromStatus:id,description',
            'toStatus:id,description',
            'seller' => fn($q) => $q->select('id', 'names as name'),
            'user' => fn($q) => $q->select('id', 'names as name'),
            'previousSeller' => fn($q) => $q->select('id', 'names as name')
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

        // Calcular estadísticas antes de paginar
        $statsQuery = clone $query;
        
        $statsByStatus = $statsQuery->select('to_status_id', \DB::raw('count(*) as total'))
            ->groupBy('to_status_id')
            ->with('toStatus:id,description')
            ->get()
            ->map(fn($item) => [
                'status' => $item->toStatus?->description ?? 'Desconocido',
                'total' => $item->total
            ]);

        $statsBySeller = (clone $query)->select('seller_id', \DB::raw('count(*) as total'))
            ->groupBy('seller_id')
            ->with(['seller' => fn($q) => $q->select('id', 'names as name')])
            ->get()
            ->map(fn($item) => [
                'seller' => $item->seller?->name ?? 'Sin asignar',
                'total' => $item->total
            ]);

        $logs = $query->orderBy('updated_at', 'desc')->paginate(50);

        return response()->json([
            'status' => true,
            'data' => $logs,
            'stats' => [
                'by_status' => $statsByStatus,
                'by_seller' => $statsBySeller,
                'total_movements' => $logs->total()
            ]
        ]);
    }

    public function getFilters()
    {
        return response()->json([
            'status' => true,
            'agents' => User::whereHas('role', fn($q) => $q->where('description', 'Vendedor'))->select('id', 'names as name')->get(),
            'statuses' => Status::select('id', 'description')->get(),
        ]);
    }
}
