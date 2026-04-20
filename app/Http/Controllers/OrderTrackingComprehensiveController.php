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

    public function cohortMetrics(Request $request)
    {
        $startDate = $request->input('start_date', now()->format('Y-m-d')) . ' 00:00:00';
        $endDate = $request->input('end_date', now()->format('Y-m-d')) . ' 23:59:59';

        $statusEntregadoId = Status::where('description', 'Entregado')->value('id');
        $statusCanceladoId = Status::where('description', 'Cancelado')->value('id');
        $statusReprogramadoId = Status::where('description', 'Reprogramado para hoy')->value('id');

        // Cohort 1: Nuevos de verdad (creados en el periodo)
        $newOrdersQuery = \App\Models\Order::with(['agent', 'shop', 'agency', 'products.product'])
            ->whereBetween('created_at', [$startDate, $endDate]);
            
        // 🛡️ SECURITY: Role-based filtering
        $user = auth()->user();
        if ($user->role?->description === 'Agencia') {
            $newOrdersQuery->where('agency_id', $user->id);
        } elseif ($user->role?->description === 'Vendedor') {
            $newOrdersQuery->where('agent_id', $user->id);
        }

        $newOrders = $newOrdersQuery->get();

        // Cohort 2: Reprogramados para hoy (tuvieron log de paso a reprogramado para hoy en el periodo)
        $rescheduledOrderIdsQuery = \App\Models\OrderTrackingComprehensiveLog::where('to_status_id', $statusReprogramadoId)
            ->whereBetween('updated_at', [$startDate, $endDate]);
            
        if ($user->role?->description === 'Agencia') {
            $rescheduledOrderIdsQuery->whereHas('order', function($q) use ($user) {
                $q->where('agency_id', $user->id);
            });
        } elseif ($user->role?->description === 'Vendedor') {
            $rescheduledOrderIdsQuery->where(function($q) use ($user) {
                $q->where('seller_id', $user->id)
                  ->orWhere('user_id', $user->id);
            });
        }
        
        $rescheduledOrderIds = $rescheduledOrderIdsQuery->pluck('order_id')->unique();

        $rescheduledOrders = \App\Models\Order::with(['agent', 'shop', 'agency', 'products.product'])
            ->whereIn('id', $rescheduledOrderIds)
            ->get();

        // Helpers to process metrics
        $processCohort = function ($orders) use ($statusEntregadoId, $statusCanceladoId) {
            $total = $orders->count();
            $delivered = $orders->where('status_id', $statusEntregadoId)->count();
            $canceled = $orders->where('status_id', $statusCanceladoId)->count();

            $byAgent = [];
            $byShop = [];
            $byAgency = [];
            $byProduct = [];

            foreach ($orders as $order) {
                $isDelivered = $order->status_id === $statusEntregadoId;
                $isCanceled = $order->status_id === $statusCanceladoId;

                // Agent
                $agentName = $order->agent ? $order->agent->names : 'Sin Asignar';
                if (!isset($byAgent[$agentName])) $byAgent[$agentName] = ['total' => 0, 'delivered' => 0, 'canceled' => 0];
                $byAgent[$agentName]['total']++;
                if ($isDelivered) $byAgent[$agentName]['delivered']++;
                if ($isCanceled) $byAgent[$agentName]['canceled']++;

                // Shop
                $shopName = $order->shop ? $order->shop->name : 'Sin Tienda';
                if (!isset($byShop[$shopName])) $byShop[$shopName] = ['total' => 0, 'delivered' => 0, 'canceled' => 0];
                $byShop[$shopName]['total']++;
                if ($isDelivered) $byShop[$shopName]['delivered']++;
                if ($isCanceled) $byShop[$shopName]['canceled']++;

                // Agency
                $agencyName = $order->agency ? $order->agency->names : 'Sin Agencia';
                if (!isset($byAgency[$agencyName])) $byAgency[$agencyName] = ['total' => 0, 'delivered' => 0, 'canceled' => 0];
                $byAgency[$agencyName]['total']++;
                if ($isDelivered) $byAgency[$agencyName]['delivered']++;
                if ($isCanceled) $byAgency[$agencyName]['canceled']++;

                // Products
                if ($order->products) {
                    foreach ($order->products as $op) {
                        $prodName = $op->product ? $op->product->name : ($op->title ?: 'Desconocido');
                        if (!isset($byProduct[$prodName])) $byProduct[$prodName] = ['total_orders' => 0, 'delivered' => 0, 'canceled' => 0];
                        // Solo sumamos 1 por orden, sin importar la cantidad (quantity) del producto, ya que se habla de "pedidos"
                        $byProduct[$prodName]['total_orders']++;
                        if ($isDelivered) $byProduct[$prodName]['delivered']++;
                        if ($isCanceled) $byProduct[$prodName]['canceled']++;
                    }
                }
            }

            // Calculate percentages
            $formatStats = function ($array, $nameKey) {
                $result = [];
                foreach ($array as $name => $data) {
                    $t = $data['total'] ?? $data['total_orders'];
                    $result[] = [
                        $nameKey => $name,
                        'total' => $t,
                        'delivered' => $data['delivered'],
                        'canceled' => $data['canceled'],
                        'delivered_rate' => $t > 0 ? round(($data['delivered'] / $t) * 100, 2) : 0,
                        'canceled_rate' => $t > 0 ? round(($data['canceled'] / $t) * 100, 2) : 0,
                    ];
                }
                usort($result, fn($a, $b) => $b['total'] <=> $a['total']);
                return $result;
            };

            return [
                'general' => [
                    'total' => $total,
                    'delivered' => $delivered,
                    'canceled' => $canceled,
                    'delivered_rate' => $total > 0 ? round(($delivered / $total) * 100, 2) : 0,
                    'canceled_rate' => $total > 0 ? round(($canceled / $total) * 100, 2) : 0,
                ],
                'by_agent'  => $formatStats($byAgent, 'agent'),
                'by_shop'   => $formatStats($byShop, 'shop'),
                'by_agency' => $formatStats($byAgency, 'agency'),
                'by_product'=> $formatStats($byProduct, 'product')
            ];
        };

        return response()->json([
            'status' => true,
            'data' => [
                'new_orders' => $processCohort($newOrders),
                'rescheduled' => $processCohort($rescheduledOrders),
            ]
        ]);
    }
}
