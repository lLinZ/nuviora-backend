<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\OrderAssignmentLog;
use App\Models\Status;
use App\Models\User;
use App\Models\Product;
use App\Models\City;
use App\Models\OrderProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BusinessMetricsController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->toDateString());
        
        $sellerId = $request->get('seller_id');
        $agencyId = $request->get('agency_id');
        $productId = $request->get('product_id');

        // Helper to query orders within range and filters
        $baseQuery = function() use ($startDate, $endDate, $sellerId, $agencyId, $productId) {
            $q = Order::whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            if ($sellerId) $q->where('agent_id', '=', $sellerId);
            if ($agencyId) $q->where('agency_id', '=', $agencyId);
            if ($productId) {
                $q->whereHas('products', function($pq) use ($productId) {
                    $pq->where('product_id', $productId);
                });
            }
            return $q;
        };

        $orders = $baseQuery()->get();
        $orderIds = $orders->pluck('id');

        // Historial de estados para los pedidos en el rango
        $statusLogs = OrderStatusLog::whereIn('order_id', $orderIds)->get();

        // 1. SECCIÓN A: FLUJO DE PEDIDOS NUEVOS
        $sectionA = $this->getSectionA($orders, $statusLogs);

        // 2. SECCIÓN B: PROGRAMADO PARA HOY
        $sectionB = $this->getSectionB($startDate, $endDate, $sellerId, $agencyId, $productId);

        // 3. SECCIÓN C: VENDEDORAS
        $sectionC = $this->getSectionC($startDate, $endDate, $sellerId, $orders, $statusLogs);

        // 4. SECCIÓN D: AGENCIAS
        $sectionD = $this->getSectionD($startDate, $endDate, $agencyId, $orders, $statusLogs);

        // 5. SECCIÓN E: PRODUCTOS
        $sectionE = $this->getSectionE($startDate, $endDate, $productId, $orders);

        return response()->json([
            'status' => true,
            'data' => [
                'sectionA' => $sectionA,
                'sectionB' => $sectionB,
                'sectionC' => $sectionC,
                'sectionD' => $sectionD,
                'sectionE' => $sectionE,
                'filters' => [
                    'sellers' => User::whereHas('role', fn($q) => $q->where('description', 'Vendedor'))->get(['id', 'names']),
                    'agencies' => User::whereHas('role', fn($q) => $q->where('description', 'Agencia'))->get(['id', 'names']),
                    'products' => Product::get(['id', 'name']),
                ]
            ]
        ]);
    }

    private function getSectionA($orders, $statusLogs)
    {
        $totalOrders = $orders->count();
        if ($totalOrders === 0) return ['tracking' => [], 'novelties' => []];

        $states = [
            'Asignado a vendedor',
            'Llamado 1', 'Llamado 2', 'Llamado 3', 
            'Programado para otro dia', 'Programado para mas tarde', 
            'Cancelado', 'Asignar a agencia', 'En ruta', 'Entregado'
        ];

        $tracking = [];
        foreach ($states as $stateName) {
            $statusId = Status::where('description', '=', $stateName)->value('id');
            if (!$statusId) continue;

            // Pedidos que pasaron por este estado (en el log o actualmente)
            $count = $orders->filter(function($o) use ($statusId, $statusLogs, $stateName) {
                // ✅ Excluir devoluciones/cambios del conteo de 'Entregado' si el usuario lo pide
                if ($stateName === 'Entregado' && ($o->is_return || $o->is_exchange)) {
                    return false;
                }
                return (int)$o->status_id === (int)$statusId || $statusLogs->where('order_id', '=', $o->id)->where('to_status_id', '=', $statusId)->isNotEmpty();
            })->count();

            $tracking[] = [
                'name' => $stateName,
                'count' => $count,
                'percentage' => round(($count / $totalOrders) * 100, 2),
                'avg_time' => 'N/A' // Requiere cálculos complejos de diferencia de timestamps en logs
            ];
        }

        // Novedades
        $noveltyOrders = $orders->filter(fn($o) => !empty($o->novedad_type));
        $totalNovelties = $noveltyOrders->count();
        
        $noveltyStats = [
            'total_percentage' => round(($totalNovelties / $totalOrders) * 100, 2),
            'avg_per_order' => round($totalNovelties / max(1, $totalOrders), 2),
            'distribution' => $noveltyOrders->groupBy('novedad_type')->map(fn($group) => [
                'count' => $group->count(),
                'percentage' => round(($group->count() / max(1, $totalNovelties)) * 100, 2)
            ])
        ];

        return [
            'tracking' => $tracking,
            'novelties' => $noveltyStats
        ];
    }

    private function getSectionB($startDate, $endDate, $sellerId, $agencyId, $productId)
    {
        // "Programado para hoy" son pedidos que tenían reminder_at en el rango seleccionado
        $scheduledOrders = Order::whereBetween('reminder_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->when($sellerId, fn($q) => $q->where('agent_id', '=', $sellerId), fn($q) => $q)
            ->when($agencyId, fn($q) => $q->where('agency_id', '=', $agencyId), fn($q) => $q)
            ->get();
        
        $total = $scheduledOrders->count();
        if ($total === 0) return ['funnel' => []];

        $finalStates = [
            'En ruta' => 'success',
            'Entregado' => 'success',
            'Cancelado' => 'error',
            'Programado para otro dia' => 'warning',
            'Programado para mas tarde' => 'warning',
        ];

        $funnel = [];
        foreach ($finalStates as $stateName => $type) {
            $statusId = Status::where('description', '=', $stateName)->value('id');
            $count = $scheduledOrders->where('status_id', '=', $statusId)->count();
            $funnel[] = [
                'name' => $stateName,
                'count' => $count,
                'percentage' => round(($count / $total) * 100, 2),
                'type' => $type
            ];
        }

        return ['total' => $total, 'funnel' => $funnel];
    }

    private function getSectionC($startDate, $endDate, $sellerId, $orders, $statusLogs)
    {
        $vendedores = User::whereHas('role', fn($q) => $q->where('description', 'Vendedor'))
            ->when($sellerId, fn($q) => $q->where('id', $sellerId))
            ->get();

        // Obtener IDs de estados relevantes
        $statusEntregadoId = Status::where('description', '=', 'Entregado')->value('id');
        $statusCanceladoId = Status::where('description', '=', 'Cancelado')->value('id');
        $statusAgenciaId = Status::where('description', '=', 'Asignar a agencia')->value('id');

        $metrics = $vendedores->map(function($v) use ($startDate, $endDate, $statusEntregadoId, $statusCanceladoId, $statusAgenciaId) {
            // ✅ Obtener todas las órdenes que fueron asignadas a esta vendedora en el rango de fechas
            // usando OrderAssignmentLog para rastrear asignaciones históricas
            $assignedOrderIds = OrderAssignmentLog::where('agent_id', '=', $v->id)
                ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->pluck('order_id')
                ->unique();

            if ($assignedOrderIds->isEmpty()) return null;

            // ✅ Obtener las órdenes directamente desde la BD (no de la colección pre-filtrada)
            // porque necesitamos órdenes asignadas en el rango, no creadas en el rango
            $vOrders = Order::whereIn('id', $assignedOrderIds)->get();
            $total = $vOrders->count();
            if ($total === 0) return null;

            // Obtener los logs de estado de estas órdenes
            $vStatusLogs = OrderStatusLog::whereIn('order_id', $assignedOrderIds)->get();
 
            // ✅ Calcular órdenes entregadas: las que llegaron al estado "Entregado"
            $entregadasCount = $vOrders->filter(function($o) use ($statusEntregadoId, $vStatusLogs) {
                // Excluir devoluciones y cambios
                if ($o->is_return || $o->is_exchange) return false;
                
                // Verificar si la orden está o estuvo en "Entregado"
                return (int)$o->status_id === (int)$statusEntregadoId || 
                       $vStatusLogs->where('order_id', '=', $o->id)
                                   ->where('to_status_id', '=', $statusEntregadoId)
                                   ->isNotEmpty();
            })->count();

            // ✅ Calcular órdenes asignadas a agencia
            $agenciaCount = $vOrders->filter(function($o) use ($statusAgenciaId, $vStatusLogs) {
                return (int)$o->status_id === (int)$statusAgenciaId || 
                       $vStatusLogs->where('order_id', '=', $o->id)
                                   ->where('to_status_id', '=', $statusAgenciaId)
                                   ->isNotEmpty();
            })->count();

            // ✅ Calcular órdenes canceladas
            $canceladasCount = $vOrders->filter(function($o) use ($statusCanceladoId, $vStatusLogs) {
                return (int)$o->status_id === (int)$statusCanceladoId || 
                       $vStatusLogs->where('order_id', '=', $o->id)
                                   ->where('to_status_id', '=', $statusCanceladoId)
                                   ->isNotEmpty();
            })->count();
 
            return [
                'id' => $v->id,
                'name' => $v->names,
                'stats' => [
                    'assigned' => $total,
                    'delivery_rate' => round(($entregadasCount / $total) * 100, 2),
                    'cancel_rate' => round(($canceladasCount / $total) * 100, 2),
                    'agency_rate' => round(($agenciaCount / $total) * 100, 2),
                ],
                'novelties' => [
                    'total' => $vOrders->filter(fn($o) => !empty($o->novedad_type))->count(),
                    'resolved_rate' => round(($vOrders->where('novedad_resolution', '!=', null)->count() / max(1, $vOrders->filter(fn($o) => !empty($o->novedad_type))->count())) * 100, 2),
                ]
            ];
        })->filter()->values();

        return ['vendedoras' => $metrics];
    }

    private function getSectionD($startDate, $endDate, $agencyId, $orders, $statusLogs)
    {
        $agencies = User::whereHas('role', fn($q) => $q->where('description', 'Agencia'))
            ->when($agencyId, fn($q) => $q->where('id', $agencyId))
            ->get();

        $metrics = $agencies->map(function($a) use ($orders, $statusLogs) {
            // Exclude orders that are still in 'Sales' statuses, even if assigned to agency ID
            $salesStatuses = [
                'Nuevo', 'Sin Stock', 'Asignado a vendedor', 
                'Llamado 1', 'Llamado 2', 'Llamado 3',
                'Esperando Ubicacion', 'Programado para mas tarde',
                'Programado para otro dia', 'Reprogramado para hoy', 'Reprogramado',
                'Confirmado' // Todavía no ha llegado a 'Asignar a agencia'
            ];
            
            $salesStatusIds = Status::whereIn('description', $salesStatuses)->pluck('id');

            $aOrders = $orders->where('agency_id', '=', $a->id)
                              ->whereNotIn('status_id', $salesStatusIds); // ✅ Filter out sales orders

            $total = $aOrders->count();
            if ($total === 0) return null;
 
            $statusRutaId = Status::where('description', '=', 'En ruta')->value('id');
            $statusEntregadoId = Status::where('description', '=', 'Entregado')->value('id');
            $statusCanceladoId = Status::where('description', '=', 'Cancelado')->value('id');
 
            return [
                'id' => $a->id,
                'name' => $a->names,
                'stats' => [
                    'received' => $total,
                    'in_route_rate' => round(($aOrders->filter(fn($o) => (int)$o->status_id === (int)$statusRutaId || $o->was_shipped)->count() / $total) * 100, 2),
                    'delivered_rate' => round(($aOrders->where('status_id', '=', $statusEntregadoId)->where('is_return', false)->where('is_exchange', false)->count() / $total) * 100, 2),
                    'cancel_rate' => round(($aOrders->where('status_id', '=', $statusCanceladoId)->count() / $total) * 100, 2),
                    'novelty_rate' => round(($aOrders->filter(fn($o) => !empty($o->novedad_type))->count() / $total) * 100, 2),
                ]
            ];
        })->filter()->values();

        return ['agencias' => $metrics];
    }

    private function getSectionE($startDate, $endDate, $productId, $orders)
    {
        $products = Product::when($productId, fn($q) => $q->where('id', $productId))->get();

        $metrics = $products->map(function($p) use ($orders) {
            // Un poco más complejo porque un pedido puede tener varios productos
            $pOrders = $orders->filter(function($o) use ($p) {
                return $o->products->where('product_id', $p->id)->isNotEmpty();
            });

            $total = $pOrders->count();
            if ($total === 0) return null;
 
            $statusEntregadoId = Status::where('description', '=', 'Entregado')->value('id');
            $statusCanceladoId = Status::where('description', '=', 'Cancelado')->value('id');
            $statusRechazadoId = Status::where('description', '=', 'Rechazado')->value('id');
 
            return [
                'id' => $p->id,
                'name' => $p->name,
                'volume' => [
                    'total' => $total,
                    'delivered' => $pOrders->where('status_id', '=', $statusEntregadoId)->count(),
                    'cancelled' => $pOrders->where('status_id', '=', $statusCanceladoId)->count(),
                    'rejected' => $pOrders->where('status_id', '=', $statusRechazadoId)->count(),
                    'in_route' => $pOrders->where('was_shipped', '=', true)->count(),
                ],
                'effectiveness' => round(($pOrders->where('status_id', '=', $statusEntregadoId)->where('is_return', false)->where('is_exchange', false)->count() / $total) * 100, 2),
                'quality' => [
                    'rejection_rate' => round(($pOrders->where('status_id', '=', $statusRechazadoId)->count() / $total) * 100, 2),
                ]
            ];
        })->filter()->values();

        return ['productos' => $metrics];
    }
}
