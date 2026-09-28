<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\StatusChangeLog;
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
        $statusLogs = StatusChangeLog::whereIn('order_id', $orderIds)->get();

        // 1. SECCIÓN A: FLUJO DE PEDIDOS NUEVOS
        $sectionA = $this->getSectionA($startDate, $endDate, $sellerId, $agencyId);

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

    private function getSectionA($startDate, $endDate, $sellerId, $agencyId)
    {
        $states = [
            'Asignado a vendedor',
            'Llamado 1', 'Llamado 2', 'Llamado 3', 
            'Programado para otro dia', 'Programado para mas tarde', 
            'Esperando Ubicacion', 
            'Cancelado', 'Asignar a agencia', 'En ruta', 'Entregado'
        ];

        // ✅ 1. Órdenes con cambios de status hoy
    $orderIdsWithLogs = StatusChangeLog::whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
        ->pluck('order_id');

    // ✅ 2. Órdenes que entren en el "Universo" del reporte:
    // Creadas hoy OR con logs hoy OR programadas para hoy
    $ordersInPeriod = Order::where(function($q) use ($startDate, $endDate, $orderIdsWithLogs) {
            $q->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
              ->orWhereIn('id', $orderIdsWithLogs)
              ->orWhereBetween('scheduled_for', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        })
        ->when($sellerId, fn($q) => $q->where('agent_id', '=', $sellerId))
        ->when($agencyId, fn($q) => $q->where('agency_id', '=', $agencyId))
        ->get();

    if ($ordersInPeriod->isEmpty()) return ['tracking' => [], 'novelties' => []];
        
        // 🔥 FIX: La BASE para el porcentaje debe ser el TOTAL DE ASIGNACIONES (Carga de trabajo), 
        // no el conteo de órdenes que tuvieron cambios de status.
        // Así los porcentajes coincidirán con la tarjeta del vendedor (ej: 7 entregadas / 41 asignadas = 17%).
        
        // 🔥 CAMBIO: Usamos updated_at para capturar la actividad real del periodo
    $assignmentBaseQuery = \App\Models\OrderAssignmentLog::whereBetween('updated_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
    if ($sellerId) {
        $assignmentBaseQuery->where('agent_id', $sellerId);
    }
    if ($agencyId) {
        $assignmentBaseQuery->whereHas('agent', function($q) use ($agencyId) {
            $q->where('agency_id', $agencyId);
        });
    }
    
    $totalAsignaciones = $assignmentBaseQuery->distinct('order_id')->count('order_id');
    
    // Si no hay asignaciones (ej. admin global sin asignaciones), usamos el conteo de órdenes activas como fallback
    $totalOrders = $totalAsignaciones > 0 ? $totalAsignaciones : 1; // Evitar división por cero

    if ($totalOrders === 0) return ['tracking' => [], 'novelties' => []];

    $tracking = [];
    foreach ($states as $stateName) {
        if ($stateName === 'Asignado a vendedor') {
            $assignmentQuery = \App\Models\OrderAssignmentLog::whereBetween('updated_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            if ($sellerId) $assignmentQuery->where('agent_id', $sellerId);
            if ($agencyId) {
                $assignmentQuery->whereHas('agent', fn($q) => $q->where('agency_id', $agencyId));
            }
            $finalIds = $assignmentQuery->distinct('order_id')->pluck('order_id');
        } else {
            // Buscamos el ID del estado de forma flexible
            $statusId = Status::whereRaw('LOWER(description) = ?', [strtolower($stateName)])->value('id');
            
            if (!$statusId) {
                $finalIds = collect([]);
            } else {
                // Logs de hoy para este estado
                $logsQuery = StatusChangeLog::where('to_status_id', $statusId)
                    ->whereBetween('updated_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                
                if ($sellerId || $agencyId) {
                    $logsQuery->where(function($q) use ($sellerId, $agencyId, $startDate, $endDate) {
                        // Opción A: Es el dueño actual según la tabla orders
                        $q->whereHas('order', function($oq) use ($sellerId, $agencyId) {
                            if ($sellerId) $oq->where('agent_id', $sellerId);
                            if ($agencyId) $oq->where('agency_id', $agencyId);
                        });

                        // Opción B: Fue asignado a este vendedor/agencia hoy según los logs
                        $q->orWhereIn('order_id', function($aq) use ($sellerId, $agencyId, $startDate, $endDate) {
                            $aq->select('order_id')
                               ->from('order_assignment_logs')
                               ->whereBetween('updated_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                            
                            if ($sellerId) {
                                $aq->where('agent_id', $sellerId);
                            }
                            
                            if ($agencyId) {
                                // Join para filtrar por agencia en los logs de asignación
                                $aq->whereIn('agent_id', function($uq) use ($agencyId) {
                                    $uq->select('id')->from('users')->where('agency_id', $agencyId);
                                });
                            }
                        });
                    });
                }

                $finalIds = $logsQuery->distinct('order_id')->pluck('order_id');
            }
        }

        // Excluir devoluciones/cambios del conteo de 'Entregado'
        if ($stateName === 'Entregado') {
             $finalIds = Order::whereIn('id', $finalIds)
                ->where('is_return', false)
                ->where('is_exchange', false)
                ->pluck('id');
        }

        $count = $finalIds->count();

        // ✅ Detalles de las órdenes
        $orderDetails = [];
        if ($count > 0) {
            $orderDetails = Order::whereIn('id', $finalIds)
                ->select('id', 'name', 'client_id')
                ->with('client:id,first_name,last_name')
                ->get()
                ->map(function($o) {
                    return [
                        'id' => $o->id,
                        'number' => str_replace('#', '', $o->name), // Limpiamos el # para la URL
                        'display_number' => $o->name,
                        'client' => $o->client ? trim($o->client->first_name . ' ' . $o->client->last_name) : 'Sin Cliente'
                    ];
                });
        }

        $tracking[] = [
            'name' => $stateName,
            'count' => $count,
            'percentage' => $totalOrders > 0 ? round(($count / $totalOrders) * 100, 2) : 0,
            'orders' => $orderDetails
        ];
    }
        // ✅ Novedades: órdenes que tuvieron actividad en el período y tienen novedad
        $noveltyOrders = $ordersInPeriod->filter(fn($o) => !empty($o->novedad_type));
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
        // 🔥 FIX: Buscar por CUALQUIER actividad en el rango, no solo reminder_at
        // Ahora incluye: Creadas hoy, Actualizadas hoy, o Agendadas para hoy.
        $scheduledOrders = Order::where(function($q) use ($startDate, $endDate) {
            $start = $startDate . ' 00:00:00';
            $end = $endDate . ' 23:59:59';
            
            $q->whereBetween('scheduled_for', [$start, $end])
              ->orWhereBetween('created_at', [$start, $end])
              ->orWhereBetween('updated_at', [$start, $end]);
        })
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
            $startDateFull = $startDate . ' 00:00:00';
            $endDateFull = $endDate . ' 23:59:59';
            
            // ✅ 1. Asignadas en el periodo (Base para la métrica)
            $assignedCount = OrderAssignmentLog::where('agent_id', '=', $v->id)
                ->whereBetween('created_at', [$startDateFull, $endDateFull])
                ->distinct('order_id')
                ->count('order_id');
                
            // ✅ 2. Métricas basadas en EVENTOS REALES en el rango de fechas (Updated At)
            // No dependemos de si la orden fue asignada hoy, sino de si se entregó/canceló HOY.
            
            // Entregadas
            $entregadasCount = StatusChangeLog::whereBetween('created_at', [$startDateFull, $endDateFull])
                ->where('to_status_id', $statusEntregadoId)
                ->whereHas('order', fn($q) => $q->where('agent_id', $v->id))
                ->distinct('order_id')
                ->count('order_id');
                
            // Canceladas
            $canceladasCount = StatusChangeLog::whereBetween('created_at', [$startDateFull, $endDateFull])
                ->where('to_status_id', $statusCanceladoId)
                ->whereHas('order', fn($q) => $q->where('agent_id', $v->id))
                ->distinct('order_id')
                ->count('order_id');
                
            // Agencia
            $agenciaCount = StatusChangeLog::whereBetween('created_at', [$startDateFull, $endDateFull])
                ->where('to_status_id', $statusAgenciaId)
                ->whereHas('order', fn($q) => $q->where('agent_id', $v->id))
                ->distinct('order_id')
                ->count('order_id');

            // Recuperamos órdenes asignadas para calcular novedades (manteniendo lógica original para novedades)
            $assignedOrderIds = OrderAssignmentLog::where('agent_id', '=', $v->id)
                ->whereBetween('created_at', [$startDateFull, $endDateFull])
                ->pluck('order_id');
            $vOrders = Order::whereIn('id', $assignedOrderIds)->get();

            // Evitar división por cero
            $base = $assignedCount > 0 ? $assignedCount : 1;
            
            return [
                'id' => $v->id,
                'name' => $v->names,
                'stats' => [
                    'assigned' => $assignedCount,
                    // Permitimos > 100% si entregan backlog de días anteriores
                    'delivery_rate' => $assignedCount > 0 ? round(($entregadasCount / $assignedCount) * 100, 2) : 0,
                    'cancel_rate' => $assignedCount > 0 ? round(($canceladasCount / $assignedCount) * 100, 2) : 0,
                    'agency_rate' => $assignedCount > 0 ? round(($agenciaCount / $assignedCount) * 100, 2) : 0,
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

        // Obtener ID del estado "Asignar a agencia"
        $statusAgenciaId = Status::where('description', '=', 'Asignar a agencia')->value('id');
        $statusRutaId = Status::where('description', '=', 'En ruta')->value('id');
        $statusEntregadoId = Status::where('description', '=', 'Entregado')->value('id');
        $statusCanceladoId = Status::where('description', '=', 'Cancelado')->value('id');

        $metrics = $agencies->map(function($a) use ($startDate, $endDate, $statusAgenciaId, $statusRutaId, $statusEntregadoId, $statusCanceladoId) {
            // ✅ Obtener todas las órdenes que fueron asignadas a esta agencia en el rango de fechas
            // Buscamos cuando el estado cambió a "Asignar a agencia" y la orden tiene este agency_id
            $assignedToAgencyLogs = StatusChangeLog::where('to_status_id', '=', $statusAgenciaId)
                ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->get();

            // Filtrar logs que corresponden a esta agencia
            $agencyOrderIds = $assignedToAgencyLogs->filter(function($log) use ($a) {
                $order = Order::find($log->order_id);
                return $order && $order->agency_id === $a->id;
            })->pluck('order_id')->unique();

            if ($agencyOrderIds->isEmpty()) return null;

            // ✅ Obtener las órdenes directamente desde la BD
            $aOrders = Order::whereIn('id', $agencyOrderIds)->get();
            $total = $aOrders->count();
            if ($total === 0) return null;

            // Obtener los logs de estado de estas órdenes
            $aStatusLogs = StatusChangeLog::whereIn('order_id', $agencyOrderIds)->get();
 
            // ✅ Calcular órdenes en ruta
            $enRutaCount = $aOrders->filter(function($o) use ($statusRutaId, $aStatusLogs) {
                return (int)$o->status_id === (int)$statusRutaId || 
                       $o->was_shipped ||
                       $aStatusLogs->where('order_id', '=', $o->id)
                                   ->where('to_status_id', '=', $statusRutaId)
                                   ->isNotEmpty();
            })->count();

            // ✅ Calcular órdenes entregadas
            $entregadasCount = $aOrders->filter(function($o) use ($statusEntregadoId, $aStatusLogs) {
                // Excluir devoluciones y cambios
                if ($o->is_return || $o->is_exchange) return false;
                
                return (int)$o->status_id === (int)$statusEntregadoId || 
                       $aStatusLogs->where('order_id', '=', $o->id)
                                   ->where('to_status_id', '=', $statusEntregadoId)
                                   ->isNotEmpty();
            })->count();

            // ✅ Calcular órdenes canceladas
            $canceladasCount = $aOrders->filter(function($o) use ($statusCanceladoId, $aStatusLogs) {
                return (int)$o->status_id === (int)$statusCanceladoId || 
                       $aStatusLogs->where('order_id', '=', $o->id)
                                   ->where('to_status_id', '=', $statusCanceladoId)
                                   ->isNotEmpty();
            })->count();
 
            return [
                'id' => $a->id,
                'name' => $a->names,
                'stats' => [
                    'received' => $total,
                    'in_route_rate' => round(($enRutaCount / $total) * 100, 2),
                    'delivered_rate' => round(($entregadasCount / $total) * 100, 2),
                    'cancel_rate' => round(($canceladasCount / $total) * 100, 2),
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
