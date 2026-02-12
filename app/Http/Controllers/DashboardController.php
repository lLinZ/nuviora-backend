<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use App\Services\EarningsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    protected $earningsService;

    public function __construct(EarningsService $earningsService)
    {
        $this->earningsService = $earningsService;
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $role = $user->role?->description;

        // Common data
        $today = Carbon::today();
        $rate = (float) (Setting::get('exchange_rate_usd', 1) ?? 1);

        $data = [
            'role' => $role,
            'today' => $today->toDateTimeString(),
            'rate' => $rate,
            'stats' => [],
        ];

        // Role specific logic
        switch ($role) {
            case 'Admin':
            case 'Gerente':
            case 'Master':
                $data['stats'] = $this->getAdminStats($today, $rate);
                break;
            case 'Vendedor':
                $data['stats'] = $this->getVendorStats($user, $today, $rate);
                break;
            case 'Repartidor':
                $data['stats'] = $this->getDelivererStats($user, $today, $rate);
                break;
            case 'Agencia':
                $data['stats'] = $this->getAgencyStats($user, $today, $rate);
                break;
            default:
                $data['stats'] = ['message' => 'No stats available for this role'];
        }

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    private function getAdminStats($date, $rate)
    {
        $statusCompletedId = Status::where('description', '=', 'Confirmado')->value('id');
        $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');
        $statusCancelledId = Status::where('description', '=', 'Cancelado')->value('id');
        $statusNewId = Status::where('description', '=', 'Nuevo')->value('id');
        $statusAssignAgencyId = Status::where('description', '=', 'Asignar a agencia')->value('id');
        
        // Define statuses that are considered "Finalized" and shouldn't be in the Action Center
        // We'll exclude 'Entregado' (Delivered) and 'Confirmado' (Confirmed)
        // But we'll KEEP 'Cancelado' if they want to see why it wasn't assigned, or just filter it if it's truly done.
        // Given your feedback, let's make it show anything WITHOUT an agency that isn't delivered.
        $finalStatuses = Status::whereIn('description', ['Entregado', 'Confirmado'])->pluck('id')->filter()->toArray();

        // General Counts
        $created = Order::whereDate('created_at', '=', $date->toDateString())->count();
        $delivered = Order::whereDate('processed_at', '=', $date->toDateString())
            ->whereIn('status_id', [$statusCompletedId, $statusDeliveredId])
            ->count();
        $cancelled = Order::whereDate('created_at', '=', $date->toDateString())->where('status_id', '=', $statusCancelledId)->count();

        $totalSales = Order::whereDate('processed_at', '=', $date->toDateString())
            ->whereIn('status_id', [$statusCompletedId, $statusDeliveredId])
            ->sum('current_total_price') ?? 0;

        // Last 7 days sales history
        $salesHistory = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $salesHistory[] = [
                'date' => $d->format('d/m'),
                'count' => Order::whereDate('created_at', '=', $d->toDateString())->count()
            ];
        }

        // Pending Actions
        $pendingRejections = \App\Models\OrderRejectionReview::where('status', 'pending')->count();
        $pendingLocations = \App\Models\OrderLocationReview::where('status', 'pending')->count();

        // Top Performers (Last 7 days)
        $sevenDaysAgo = Carbon::today()->subDays(7);
        
        $topSellers = User::whereHas('role', fn($q) => $q->where('description', 'Vendedor'))
            ->withCount(['agentOrders' => function($q) use ($sevenDaysAgo) {
                $q->where('created_at', '>=', $sevenDaysAgo);
            }])
            ->orderBy('agent_orders_count', 'desc')
            ->limit(5)
            ->get(['id', 'names']);

        $topDeliverers = User::whereHas('role', fn($q) => $q->where('description', 'Repartidor'))
            ->withCount(['delivererOrders' => function($q) use ($sevenDaysAgo) {
                $q->where('processed_at', '>=', $sevenDaysAgo)
                  ->whereHas('status', fn($s) => $s->where('description', 'Entregado'));
            }])
            ->orderBy('deliverer_orders_count', 'desc')
            ->limit(5)
            ->get(['id', 'names']);

        $statusSinStockId = Status::where('description', '=', 'Sin Stock')->value('id');
        
        // 📉 Inventory Deficit Analysis (Identify exactly what is blocking 'Sin Stock' orders)
        $sinStockOrders = Order::where('status_id', '=', $statusSinStockId)
            ->with(['products', 'agency'])
            ->get();

        $deficitByAgency = [];

        foreach ($sinStockOrders as $order) {
            $agency = $order->agency;
            if (!$agency) continue;

            $agencyId = $agency->id;
            $agencyName = $agency->names ?? "Agencia #{$agencyId}";

            if (!isset($deficitByAgency[$agencyId])) {
                $deficitByAgency[$agencyId] = [
                    'agency_id' => $agencyId,
                    'agency_name' => $agencyName,
                    'products' => []
                ];
            }

            // Get warehouse for this agency
            $warehouse = \App\Models\Warehouse::where('user_id', '=', $agencyId)->first();
            
            foreach ($order->products as $orderProduct) {
                $productId = $orderProduct->product_id;
                $productName = $orderProduct->title ?? $orderProduct->name ?? "Producto #{$productId}";

                // Check stock in warehouse
                $stock = 0;
                if ($warehouse) {
                    $inv = \App\Models\Inventory::where('warehouse_id', '=', $warehouse->id)
                        ->where('product_id', '=', $productId)
                        ->first();
                    $stock = $inv ? (int) $inv->quantity : 0;
                }

                if ($stock < $orderProduct->quantity) {
                    if (!isset($deficitByAgency[$agencyId]['products'][$productId])) {
                        $deficitByAgency[$agencyId]['products'][$productId] = [
                            'name' => $productName,
                            'current_stock' => $stock,
                            'total_required' => 0
                        ];
                    }
                    $deficitByAgency[$agencyId]['products'][$productId]['total_required'] += $orderProduct->quantity;
                }
            }
        }

        $formattedDeficit = array_values(array_map(function($agency) {
            $agency['products'] = array_values($agency['products']);
            return $agency;
        }, $deficitByAgency));

        // 🛡️ Proactive Alert: Any product in any agency with 0 < qty < 15 units
        $lowStockAlerts = [];
        $activeWarehouses = \App\Models\Warehouse::where('is_active', true)->get();
        $allProducts = \App\Models\Product::all();

        foreach ($activeWarehouses as $wh) {
            $warehouseProducts = [];
            foreach ($allProducts as $prod) {
                $inv = \App\Models\Inventory::where('warehouse_id', $wh->id)
                    ->where('product_id', $prod->id)
                    ->first();
                
                $qty = $inv ? (int) $inv->quantity : 0;

                if ($qty > 0 && $qty < 15) {
                    $warehouseProducts[] = [
                        'name' => $prod->name ?? $prod->title ?? 'Producto',
                        'quantity' => $qty
                    ];
                }
            }

            if (!empty($warehouseProducts)) {
                $lowStockAlerts[] = [
                    'warehouse_name' => $wh->name ?? 'Agencia',
                    'products' => $warehouseProducts
                ];
            }
        }

        return [
            'total_sales' => $totalSales,
            'orders_today' => [
                'created' => $created,
                'delivered' => $delivered,
                'cancelled' => $cancelled,
            ],
            'inventory_deficit' => $formattedDeficit,
            'low_stock_alerts' => $lowStockAlerts,
            'orders_sin_stock_count' => $sinStockOrders->count(),
            'unassigned_agency_count' => (int) Order::whereNull('agency_id')->count(),
            'unassigned_agency_orders' => Order::whereNull('agency_id')->latest()->limit(5)->get(['id', 'name', 'current_total_price', 'created_at']),
            'unassigned_city_count' => (int) Order::whereNull('city_id')->count(),
            'missing_cities_summary' => Order::whereNull('city_id')
                ->with('client')
                ->get()
                ->groupBy(fn($o) => ucfirst(strtolower(trim($o->client->city ?? 'Desconocida'))))
                ->map(fn($group) => $group->count())
                ->sortDesc()
                ->toArray(),
            'pending_reviews' => [
                'rejections' => $pendingRejections,
                'locations' => $pendingLocations,
            ],
            'top_sellers' => $topSellers,
            'top_deliverers' => $topDeliverers,
            'sales_history' => $salesHistory
        ];
    }

    private function getVendorStats($user, $date, $rate)
    {
        $statusCompletedId = Status::where('description', '=', 'Confirmado')->value('id');
        $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');
        $statusCancelledId = Status::where('description', '=', 'Cancelado')->value('id');
        
        $commissionPerOrder = 1.0; // $1 USD
        $commissionPerUpsell = 1.0; // $1 USD

        // Counts Today
        $assigned = Order::whereDate('created_at', '=', $date->toDateString())->where('agent_id', '=', $user->id)->count();
        
        $completedOrdersQuery = Order::whereDate('processed_at', '=', $date->toDateString())
            ->where('status_id', '=', $statusCompletedId)
            ->where('agent_id', '=', $user->id);

        $completed = $completedOrdersQuery->count();
        $completedOrderIds = $completedOrdersQuery->pluck('id');
        
        $delivered = Order::whereDate('processed_at', '=', $date->toDateString())
            ->where('status_id', '=', $statusDeliveredId)
            ->where('agent_id', '=', $user->id)
            ->count();

        $cancelled = Order::whereDate('created_at', '=', $date->toDateString())
            ->where('status_id', '=', $statusCancelledId)
            ->where('agent_id', '=', $user->id)
            ->count();
        
        // Comisiones desde la tabla de ganancias
        $earningsToday = \App\Models\Earning::where('user_id', $user->id)
            ->whereDate('earning_date', $date->toDateString())
            ->get();

        $totalEarningsUsd = $earningsToday->sum('amount_usd');
        $orderEarnings = $earningsToday->where('role_type', 'vendedor')->sum('amount_usd');
        $upsellEarnings = $earningsToday->where('role_type', 'upsell')->sum('amount_usd');
        $upsellCount = $upsellEarnings; // Asumiendo $1 por upsell


        // Recent Orders
        $recentOrders = Order::where('agent_id', $user->id)
            ->with(['status', 'client'])
            ->latest()
            ->limit(6)
            ->get();

        // Weekly Sales History (Confirmadas)
        $salesHistory = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $count = Order::whereDate('updated_at', '=', $d->toDateString()) // updated_at ~ processed_at for confirmation
                ->where('agent_id', $user->id)
                ->where('status_id', $statusCompletedId)
                ->count();
            $salesHistory[] = [
                'date' => $d->format('d/m'),
                'count' => $count
            ];
        }

        // Orders with Change Receipt (for notification checklist)
        $ordersWithChange = Order::where('agent_id', $user->id)
            ->whereHas('changeExtra', function($q) {
                $q->whereNotNull('change_receipt')->where('change_receipt', '!=', '');
            })
            ->with(['changeExtra', 'client', 'status'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(function($o) {
                $details = $o->changeExtra->change_payment_details ?? [];
                $o->client_notified = isset($details['client_notified']) && $details['client_notified'] === true;
                return $o;
            });

        return [
            'earnings_usd' => $totalEarningsUsd,
            'earnings_local' => $totalEarningsUsd * $rate,
            'earnings_breakdown' => [
                'orders' => $orderEarnings,
                'upsells' => $upsellEarnings,
                'upsell_count' => (int)$upsellCount
            ],
            'orders' => [
                'assigned' => $assigned,
                'completed' => $completed,
                'delivered' => $delivered,
                'cancelled' => $cancelled,
            ],
            'recent_orders' => $recentOrders,
            'sales_history' => $salesHistory,
            'pending_vueltos' => $ordersWithChange,
            'rule' => '1 USD por orden + 1 USD por upsell'
        ];
    }

    private function getDelivererStats($user, $date, $rate)
    {
        // IDs de status importantes
        $statusDeliveredId = \App\Models\Status::where('description', 'Entregado')->value('id');
        $commissionPerOrder = 2.5; // $2.5 USD

        $assigned = Order::whereDate('created_at', '=', $date)->where('deliverer_id', '=', $user->id)->count();
        $delivered = Order::whereDate('processed_at', '=', $date)
            ->where('status_id', '=', $statusDeliveredId)
            ->where('deliverer_id', '=', $user->id)
            ->count();
            
        $earningsUsd = \App\Models\Earning::where('user_id', $user->id)
            ->whereDate('earning_date', $date->toDateString())
            ->where('role_type', 'repartidor')
            ->sum('amount_usd');

        // Stock count (assigned items not yet delivered/returned)
        // For simplicity, just count products in "Deliverer Stock" using DelivererStockController logic if needed
        // Or just return 0 for now until we link that up.
        
        return [
            'earnings_usd' => $earningsUsd,
            'earnings_local' => $earningsUsd * $rate,
            'orders' => [
                'assigned' => $assigned,
                'delivered' => $delivered,
            ],
            'rule' => '2.5 USD por orden entregada'
        ];
    }

    private function getAgencyStats($user, $date, $rate)
    {
        $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');
        
        $statusCancelledId = Status::where('description', '=', 'Cancelado')->value('id');
        
        // 'Asignadas Hoy': Órdenes que llegaron a la agencia HOY.
        // Buscamos en el historial de status si la orden TRANSICIONÓ a un estado de agencia HOY.
        // Estados relevantes de entrada: 'Asignar a agencia', 'Asignar repartidor' (si saltó directo).
        $statusAsignarAgenciaId = Status::where('description', '=', 'Asignar a agencia')->value('id');
        $statusAsignarRepartidorId = Status::where('description', '=', 'Asignar repartidor')->value('id');
        
        $assigned = \App\Models\OrderStatusLog::whereDate('created_at', $date)
            ->where('to_status_id', $statusAsignarAgenciaId)
            ->whereHas('order', function($q) use ($user) {
                $q->where('agency_id', $user->id);
            })
            ->distinct('order_id')
            ->count('order_id');

        $deliveredCount = Order::where('agency_id', '=', $user->id)
            ->where('status_id', '=', $statusDeliveredId)
            ->whereDate('processed_at', $date)
            ->count();

        // Calculate real net sales (Collected - Change) using EarningsService logic
        $settlement = $this->earningsService->calculateAgencySettlement($date, $date, $user->id);
        $item = $settlement->first();
        $totalNetUsd = $item ? (float) ($item['balance_usd'] ?? 0) : 0;
        $deliveredCountFromSettlement = $item ? (int) ($item['count_delivered'] ?? 0) : 0;

        $totalSales = $totalNetUsd;

        // Pending Route: Orders assigned to this agency currently in statuses that imply "Waiting for route/delivery"
        // Statuses: 'Asignar a agencia', 'Asignar repartidor', 'Novedades', 'Novedad Solucionada'
        // NOT 'En ruta' (that's already routed), NOT 'Entregado', NOT 'Cancelado'.
        $pendingStatuses = Status::whereIn('description', [
            'Asignar a agencia', 
            'Asignar repartidor', 
            'Novedades', 
            'Novedad Solucionada'
        ])->pluck('id')->toArray();

        $pendingRoute = Order::where('agency_id', '=', $user->id)
            ->whereIn('status_id', $pendingStatuses)
            ->with(['client', 'status', 'deliverer'])
            ->limit(10)
            ->get();

        $pendingCount = Order::where('agency_id', '=', $user->id)
            ->whereIn('status_id', $pendingStatuses)
            ->count();

        // Calculate commissions from Earning table
        $earningsUsd = \App\Models\Earning::where('user_id', $user->id)
            ->whereDate('earning_date', $date->toDateString())
            ->where('role_type', 'agencia')
            ->sum('amount_usd');

        return [
            'total_sales' => (float) $totalSales,
            'earnings_usd' => (float) $earningsUsd,
            'earnings_local' => (float) ($earningsUsd * $rate),
            'orders_today' => [
                'assigned' => $assigned,
                'delivered' => $deliveredCount ?: $deliveredCountFromSettlement,
                'pending'   => $pendingCount
            ],
            'pending_route_orders' => $pendingRoute,
            'message' => 'Gestión logística de tu agencia'
        ];
    }
}
