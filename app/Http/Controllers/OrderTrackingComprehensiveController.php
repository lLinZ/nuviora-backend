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

        // 🛡️ SECURITY: Role-based filtering
        $user = auth()->user();
        if ($user->role?->description === 'Agencia') {
            $query->whereHas('order', function($q) use ($user) {
                $q->where('agency_id', $user->id);
            });
        } elseif ($user->role?->description === 'Vendedor') {
            $query->where(function($q) use ($user) {
                $q->where('seller_id', $user->id)
                  ->orWhere('user_id', $user->id);
            });
        }

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
        $allLogs = (clone $query)->get();
        
        // 1. Movimientos por Status (Órdenes únicas por cada estado)
        $statsByStatus = $allLogs->groupBy('to_status_id')->map(fn($group) => [
            'status' => $group->first()->toStatus?->description ?? 'Desconocido',
            'total' => $group->unique('order_id')->count()
        ])->values();

        // 2. Movimientos por vendedora + Tasa de entrega
        $statusEntregadoId = Status::where('description', 'Entregado')->value('id');
        $statusAsignarAgenciaId = Status::where('description', 'Asignar a agencia')->value('id');
        $statusNovedadId = Status::where('description', 'Novedades')->value('id');
        $statusSolucionadaId = Status::where('description', 'Novedad Solucionada')->value('id');

        $statsBySeller = $allLogs->groupBy('seller_id')->map(function ($group) use ($statusEntregadoId) {
            $seller = $group->first()->seller;
            $totalMovements = $group->count();
            
            // Tasa de entrega: órdenes de esta vendedora que pasaron a Entregado en este periodo
            $deliveredOrdersCount = $group->where('to_status_id', $statusEntregadoId)->unique('order_id')->count();
            $uniqueOrdersCount = $group->unique('order_id')->count();
            $deliveryRate = $uniqueOrdersCount > 0 ? round(($deliveredOrdersCount / $uniqueOrdersCount) * 100, 2) : 0;

            return [
                'seller' => $seller?->name ?? 'Sin asignar',
                'total' => $totalMovements,
                'delivered' => $deliveredOrdersCount,
                'unique_orders' => $uniqueOrdersCount,
                'delivery_rate' => $deliveryRate . '%',
                'delivery_rate_numeric' => $deliveryRate
            ];
        })->values();

        // 3. Métricas Globales Adicionales
        $totalUniqueOrders = $allLogs->unique('order_id')->count();
        $agencyAssignments = $allLogs->where('to_status_id', $statusAsignarAgenciaId)->unique('order_id')->count();
        $agencyRate = $totalUniqueOrders > 0 ? round(($agencyAssignments / $totalUniqueOrders) * 100, 2) : 0;

        $noveltiesCount = $allLogs->where('to_status_id', $statusNovedadId)->unique('order_id')->count();
        $resolvedCount = $allLogs->where('to_status_id', $statusSolucionadaId)->unique('order_id')->count();
        $resolutionRate = $noveltiesCount > 0 ? round(($resolvedCount / $noveltiesCount) * 100, 2) : 0;

        $logs = $query->orderBy('updated_at', 'desc')->paginate(50);

        return response()->json([
            'status' => true,
            'data' => $logs,
            'stats' => [
                'by_status' => $statsByStatus,
                'by_seller' => $statsBySeller,
                'total_movements' => $allLogs->count(),
                'total_orders' => $totalUniqueOrders,
                'agency_rate' => $agencyRate . '%',
                'novelty_stats' => [
                    'total' => $noveltiesCount,
                    'resolved' => $resolvedCount,
                    'rate' => $resolutionRate . '%'
                ]
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
